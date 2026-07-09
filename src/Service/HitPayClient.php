<?php

declare(strict_types=1);

namespace Drupal\commerce_hitpay\Service;

use Drupal\commerce_hitpay\Exception\HitPayException;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use Drupal\Core\Logger\LoggerChannelInterface;

/**
 * HitPay API client.
 *
 * A configured client for communicating with the HitPay REST API.
 */
final class HitPayClient {
	
	private const EVENT_PAYMENT_COMPLETED = 'charge.created';
	private const EVENT_PAYMENT_FAILED = 'charge.failed';

	private const WEBHOOK_EVENTS = [
		self::EVENT_PAYMENT_COMPLETED,
		self::EVENT_PAYMENT_FAILED,
	];
	
	private const WEBHOOK_NAME = 'Drupal Commerce 3.x';

  /**
   * Live API base URL.
   */
  private const LIVE_API_URL = 'https://api.hit-pay.com/v1';

  /**
   * Sandbox API base URL.
   */
  private const SANDBOX_API_URL = 'https://api.sandbox.hit-pay.com/v1';

  /**
  * Constructor.	
	*/

  public function __construct(
    private readonly ClientInterface $httpClient,
		private readonly LoggerChannelInterface $logger,
    private readonly string $apiKey,
    private readonly bool $sandbox,
		private readonly bool $debug = FALSE,
  ) {}
  
  
  /**
   * Tests the API connection.
   */
  public function testConnection(): bool {
    try {
      $this->request('GET', '/payment-requests');
      return TRUE;
    }
    catch (HitPayException) {
      return FALSE;
    }
  }

  /**
   * Creates a payment request.
   *
   * @param array $payload
   *   HitPay payment request payload.
   *
   * @return array
   *   Decoded API response.
   *
   * @throws \Drupal\commerce_hitpay\Exception\HitPayException
   */
  public function createPaymentRequest(array $payload): array {
    return $this->request(
      'POST',
      '/payment-requests',
      [
        'form_params' => $payload,
      ],
    );
  }
  
  
  /**
   * Retrieves payment details.
   */
  public function getPayment(string $paymentId): array {
    return $this->request(
      'GET',
      '/payment-requests/' . $paymentId,
    );
  }

  /**
   * Creates a webhook.
   */
  public function createWebhook(string $url): array {
		$payload = $this->prepareWebhookPayload($url);
		
    return $this->request(
      'POST',
      '/webhook-events',
      [
        'json' => $payload,
      ],
    );
  }
  
   /**
   * Update a webhook.
   */
  public function updateWebhook(string $webhookId, string $url): array {
		$payload = $this->prepareWebhookPayload($url);
		
		return $this->request(
      'PUT',
      '/webhook-events/' . $webhookId,
      [
        'json' => $payload,
      ],
    );
  }
  
  /**
   * Retrieves webhook details.
   */
  public function getWebhook(string $webhookId): array {
    return $this->request(
      'GET',
      '/webhook-events/' . $webhookId,
    );
  }
	
	/**
   * Retrieves webhooks.
   */
  public function getWebhooks() {
    return $this->request(
      'GET',
      '/webhook-events'
    );
  }

  /**
   * Deletes a webhook.
   */
  public function deleteWebhook(string $webhookId): void {
    $this->request(
      'DELETE',
      '/webhook-events/' . $webhookId,
    );
  }

   /**
   * Register a webhook.
   */
	public function registerWebhook(
	  string $url,
	  ?string $webhookId = NULL,
	): array {
		if (empty($webhookId)) {
			return $this->replaceWebhooksForUrl($url);
		}
	  
		try {
			$existingWebhook = $this->getWebhook($webhookId);
		}
		catch (HitPayException $e) {
			if ($e->getCode() === 404) {
				return $this->replaceWebhooksForUrl($url);
			}

			throw $e;
		}
		
		if (empty($existingWebhook['id'])) {
			throw new HitPayException(
					'HitPay returned an invalid webhook response: missing webhook ID.'
			);
		}

		if (!array_key_exists('url', $existingWebhook)) {
			throw new HitPayException(
					'HitPay returned an invalid webhook response: missing webhook URL.'
			);
		}

		if ((string) $existingWebhook['url'] !== $url) {
			return $this->updateWebhook($webhookId, $url);
		}

		return $existingWebhook;
	}
	
	/**
	 * Deletes existing webhooks for the URL and creates a new webhook.
	 *
	 * @throws \Drupal\commerce_hitpay\Exception\HitPayException
	 */
	private function replaceWebhooksForUrl(string $url) {
		$webhooks = $this->getWebhooks();

		foreach ($webhooks as $webhook) {
			if (
					is_array($webhook) &&
					!empty($webhook['id']) &&
					isset($webhook['url']) &&
					(string) $webhook['url'] === $url
			) {
				try {
					$this->deleteWebhook((string) $webhook['id']);
				}
				catch (HitPayException $e) {
					// A webhook disappearing between GET and DELETE is harmless.
					if ($e->getCode() !== 404) {
							throw $e;
					}
				}
			}
		}

		return $this->createWebhook($url);
	}
	
   /**
	 * Creates a refund for a completed HitPay payment.
	 *
	 * @param string $paymentId
	 *   The remote HitPay payment ID.
	 * @param string $amount
	 *   The refund amount formatted as a decimal string (e.g. "10.00").
	 *
	 * @return array
	 *   The decoded HitPay API response.
	 *
	 * @throws \Drupal\commerce_hitpay\Exception\HitPayException
	 *   Thrown when the API request fails.
    */
  public function createRefund(string $paymentId, string $amount): array {
	  
		$payload = [
			'amount' => $amount,
			'payment_id' => $paymentId,
		];
	
    return $this->request(
      'POST',
      '/refund',
      [
        'json' => $payload,
      ],
    );
  }

  /**
   * Sends a request to the HitPay API.
   *
   * @throws \Drupal\commerce_hitpay\Exception\HitPayException
   */
  private function request(
    string $method,
    string $uri,
    array $options = [],
  ): array {

		$options['headers'] = array_merge(
			$this->getDefaultHeaders(),
			$options['headers'] ?? []
		);

    $baseUrl = $this->getBaseUrl();
		
		if ($this->debug) {
			$this->logger->debug(
				'HitPay API [@environment] request: @method @uri. Payload: @payload',
				[
					'@method' => $method,
					'@uri' => $uri,
					'@payload' => $this->encodeDebugValue(
						$this->extractRequestPayload($options)
					),
					'@environment' => $this->sandbox ? 'sandbox' : 'live'
				]
			);
		}

    try {
      $response = $this->httpClient->request(
        $method,
        $baseUrl . $uri,
        $options,
      );
    }
    catch (GuzzleException $e) {
			$status_code = 0;
			$error_body = '';

			if ($e instanceof RequestException && $e->hasResponse()) {
				$status_code = $e->getResponse()->getStatusCode();
				$error_body = (string) $e->getResponse()->getBody();
			}

			if ($this->debug) {
				$this->logger->debug(
					'HitPay API [@environment] request failed: @method @uri. HTTP status: @status. Response: @response. Error: @message',
					[
						'@method' => $method,
						'@uri' => $uri,
						'@status' => $status_code,
						'@response' => $this->prepareResponseForDebug($error_body),
						'@message' => $e->getMessage(),
						'@environment' => $this->sandbox ? 'sandbox' : 'live'
					]
				);
			}
			
      throw new HitPayException(
				$e->getMessage(),
				$status_code,
				$e
			);
    }

    $body = (string) $response->getBody();
		
		if ($this->debug) {
			$this->logger->debug(
				'HitPay API [@environment] response: @method @uri. HTTP status: @status. Body: @body',
				[
					'@method' => $method,
					'@uri' => $uri,
					'@status' => $response->getStatusCode(),
					'@body' => $this->prepareResponseForDebug($body),
					'@environment' => $this->sandbox ? 'sandbox' : 'live'
				]
			);
		}

    try {
			return $body === ''
				? []
				: json_decode(
					$body,
					TRUE,
					512,
					JSON_THROW_ON_ERROR,
				);
		}
		catch (\JsonException $e) {
			throw new HitPayException(
				'Invalid JSON received from HitPay.',
				0,
				$e,
			);
		}
  }
  
   /**
   * Return default headers.
   */
  private function getDefaultHeaders(): array {
	  return [
			'X-BUSINESS-API-KEY' => $this->apiKey,
			'Accept' => 'application/json',
		];
  }
	
  /**
   * Prepare Webhook Payload
   */
  private function prepareWebhookPayload(string $url) {
	  return [
			'name' => self::WEBHOOK_NAME,
			'url' => $url,
			'event_types' => self::WEBHOOK_EVENTS
		];
  }
  
  /**
   * Return API URL.
   */
  private function getBaseUrl(): string {
	  return $this->sandbox
			? self::SANDBOX_API_URL
			: self::LIVE_API_URL;
  }
	
	/**
	 * Extracts the API request payload for debug logging.
	 *
	 * @param array $options
	 *   Guzzle request options.
	 *
	 * @return array
	 *   Request payload.
	 */
	private function extractRequestPayload(array $options) {
		if (isset($options['json']) && is_array($options['json'])) {
			return $this->sanitizeDebugData($options['json']);
		}

		if (
			isset($options['form_params']) &&
			is_array($options['form_params'])
		) {
			return $this->sanitizeDebugData($options['form_params']);
		}

		return [];
	}
	
	/**
	 * Removes sensitive values from debug log data.
	 *
	 * @param array $data
	 *   Data to sanitize.
	 *
	 * @return array
	 *   Sanitized data.
	 */
	private function sanitizeDebugData(array $data) {
		$sensitive_keys = [
			'api_key',
			'api_secret',
			'authorization',
			'salt',
			'signature',
			'card_number',
			'card',
			'cvv',
			'cvc',
			'email',
			'phone',
			'phone_number',
			'name',
			'first_name',
			'last_name',
			'customer_name',
			'customer_email',
			'buyer_phone',
			'buyer_email',
			'buyer_phone',
		];

		foreach ($data as $key => $value) {
			if (in_array(strtolower((string) $key), $sensitive_keys, TRUE)) {
				$data[$key] = '[REDACTED]';
				continue;
			}

			if (is_array($value)) {
				$data[$key] = $this->sanitizeDebugData($value);
			}
		}

		return $data;
	}
	
	/**
	 * Encodes a value for debug logging.
	 *
	 * @param mixed $value
	 *   Value to encode.
	 *
	 * @return string
	 *   JSON representation.
	 */
	private function encodeDebugValue($value) {
		$encoded = json_encode(
			$value,
			JSON_UNESCAPED_SLASHES |
			JSON_UNESCAPED_UNICODE
		);

		return $encoded === FALSE
			? '[Unable to encode debug data]'
			: $encoded;
	}
	
	/**
	 * Prepares an API response body for debug logging.
	 *
	 * @param string $body
	 *   Raw API response body.
	 *
	 * @return string
	 *   Sanitized JSON representation.
	 */
	private function prepareResponseForDebug(string $body) {
		if ($body === '') {
			return '';
		}

		$decoded = json_decode($body, TRUE);

		if (!is_array($decoded)) {
			return '[Non-JSON response omitted]';
		}

		return $this->encodeDebugValue(
			$this->sanitizeDebugData($decoded)
		);
	}
}