<?php

declare(strict_types=1);

namespace Drupal\commerce_hitpay\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Drupal\commerce_hitpay\Manager\PaymentManager;
use Drupal\commerce_payment\Entity\PaymentGatewayInterface;

/**
 * Processes HitPay webhooks.
 */
final class WebhookProcessor {
	
	/**
	 * HitPay webhook header event values.
	 */
	private const EVENT_PAYMENT_COMPLETED = 'created';
	private const EVENT_PAYMENT_FAILED = 'failed';

	/**
	 * HitPay payment status values.
	 */
	private const STATUS_COMPLETED = 'succeeded';
	private const STATUS_FAILED = 'failed';

  /**
   * Constructor.
   */
  public function __construct(
    private readonly SignatureVerifier $signatureVerifier,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly LoggerChannelInterface $logger,
		private readonly PaymentManager $paymentManager,
  ) {}

  /**
   * Process the webhook.
   */
  public function process(
    Request $request,
    string $webhookSalt,
		PaymentGatewayInterface $commerce_payment_gateway,
  ): JsonResponse {

    try {

      // Extract the raw payload data from the incoming request.
      $payload = $request->getContent();
	  
			if ($payload === '') {
				return new JsonResponse([
					'message' => 'Empty request body.',
				], 400);
			}

      // Extract and verify the HitPay cryptographic signature from headers.
      $signature = $request->headers->get('Hitpay-Signature');

      if (empty($signature)) {
        $this->logger->warning('HitPay signature header missing.');
        return new JsonResponse([
          'message' => 'Missing signature.',
        ], 400);
      }

      if (!$this->signatureVerifier->verify(
        $payload,
        $signature,
        $webhookSalt,
      )) {
        $this->logger->warning('Invalid HitPay webhook signature.');
        return new JsonResponse([
          'message' => 'Invalid signature.',
        ], 403);
      }

      // Decode payload.

			$data = json_decode(
				$payload,
				TRUE,
				512,
				JSON_THROW_ON_ERROR,
			);

      if (!is_array($data)) {
        return new JsonResponse([
          'message' => 'Invalid JSON.',
        ], 400);
      }
	  
			$paymentRequestId = $data['payment_request_id'] ?? NULL;
			$channel = $data['channel'] ?? NULL;
	  
			if ($channel !== 'payment_gateway') {
				$this->logger->notice(
					'Ignoring unsupported payment channel {channel}.',
					[
						'channel' => $channel,
					],
				);

				return new JsonResponse([
					'message' => 'Ignored.',
				], 200);
			}
	  
			if (empty($paymentRequestId)) {
				$this->logger->warning(
					'Webhook missing payment request ID.',
				);
				return new JsonResponse([
					'message' => 'Missing payment request ID.',
				], 400);  
			}

      // Validate required fields.
			$eventType = $request->headers->get('Hitpay-Event-Type');
			$eventObject = $request->headers->get('Hitpay-Event-Object');
	  
			if (empty($eventType) || empty($eventObject)) {
				$this->logger->warning('Missing required HitPay webhook headers.');
				return new JsonResponse([
					'message' => 'Missing webhook headers.',
				], 400);
			}

			if (!in_array($eventType, [
				self::EVENT_PAYMENT_COMPLETED,
				self::EVENT_PAYMENT_FAILED,
			], TRUE)) {
				 $this->logger->notice(
					'Ignoring payment with event {event}.',
					[
						'event' => $eventType,
					]
				);
				return new JsonResponse([
					'message' => 'Ignored.',
				], 200);
			}
	
			if ($eventObject !== 'charge') {
				$this->logger->notice(
				'Ignoring webhook object {object}.',
				[
					'object' => $eventObject,
				]
				);

				return new JsonResponse([
					'message' => 'Ignored.',
				], 200);
			}
	  
			$paymentRequest = $data['payment_request'] ?? [];

			if (!is_array($paymentRequest)) {
				$this->logger->warning(
					'Webhook contains an invalid payment_request object.',
				);
				return new JsonResponse([
					'message' => 'Invalid payment request.',
				], 400);
			}
	
			$reference = $paymentRequest['reference_number'] ?? NULL;
      $status = $data['status'] ?? NULL;
      $remoteId = $data['id'] ?? NULL;
	  
      if (!$reference || !$remoteId || !$status) {
				$this->logger->warning(
					'Webhook payload missing required fields.'
				);
				return new JsonResponse([
					'message' => 'Webhook missing required fields.'
				], 400);
			}
	  
      // Find Commerce Order.
      $orders = $this->entityTypeManager
        ->getStorage('commerce_order')
        ->loadByProperties([
          'uuid' => $reference,
        ]);

      if (empty($orders)) {
				$this->logger->notice(
					'Ignoring webhook because no Commerce order matches reference {order}.',
					[
						'order' => $reference
					]
				);
        return new JsonResponse([
          'message' => 'Ignored.'
        ], 200);
      }

      /** @var \Drupal\commerce_order\Entity\OrderInterface $order */
      $order = reset($orders);
			
			$order_payment_gateway_id = (string) $order->get('payment_gateway')->target_id;
			$webhook_payment_gateway_id = (string) $commerce_payment_gateway->id();

			if (
				$order_payment_gateway_id === '' ||
				$order_payment_gateway_id !== $webhook_payment_gateway_id
			) {
				$this->logger->notice(
					'Ignoring HitPay webhook for order {order}: order gateway {order_gateway} does not match webhook gateway {webhook_gateway}.',
					[
						'order' => $reference,
						'order_gateway' => $order_payment_gateway_id,
						'webhook_gateway' => $webhook_payment_gateway_id,
					]
				);

				return new JsonResponse([
					'message' => 'Ignored.'
				], 200);
			}
			
			$expected_payment_request_id = (string) $order->getData(
				'hitpay_payment_request_id'
			);

			if (
				$expected_payment_request_id === '' ||
				$expected_payment_request_id !== (string) $paymentRequestId
			) {
				$this->logger->notice(
					'Ignoring HitPay webhook for order {order}: payment request ID does not match the active HitPay payment request.',
					[
						'order' => $reference,
					]
				);

				return new JsonResponse([
					'message' => 'Ignored.'
				], 200);
			}

      // Evaluate payment status parameters.
	  
			if ($status === self::STATUS_FAILED) {
        $this->logger->notice('Payment failed for order {order}.', ['order' => $reference]);
        return new JsonResponse([
          'message' => 'Payment failed.'
        ], 200);
      }
		
			if ($status !== self::STATUS_COMPLETED) {
        $this->logger->notice('Ignoring payment with unexpected status {status} for order {order}.', [
          'status' => $status,
          'order' => $reference
        ]);
        return new JsonResponse([
          'message' => 'Ignored.'
        ], 200);
      }

			// Check if a payment for this HitPay transaction has already been logged.
      if ($this->paymentManager->paymentExists($remoteId)) {
        $this->logger->info('Duplicate webhook ignored for HitPay payment ID {id}.', ['id' => $remoteId]);
        return new JsonResponse([
          'message' => 'Already processed.',
        ], 200);
      }
		
      // Log successful transaction and advance order state.
      // Extract the true gateway ID string directly out of our route param injection
      $paymentGatewayId = $commerce_payment_gateway->id();

			$this->paymentManager->createCompletedPayment(
				$order,
				$remoteId,
				$paymentGatewayId,
				$status,
			);

      $this->logger->info(
        'Webhook validated successfully for order {order}.',
        [
          'order' => $reference,
        ]
      );

      return new JsonResponse([
        'message' => 'OK',
      ], 200);

    }
    catch (\JsonException  $e) {
      $this->logger->warning('Invalid webhook JSON: @message', [
				'@message' => $e->getMessage(),
			]);

			return new JsonResponse([
				'message' => 'Invalid JSON.',
			], 400);
    }
    catch (\Throwable $e) {
      $this->logger->error(
				'HitPay webhook processing failed: @message',
				[
					'@message' => $e->getMessage(),
					'exception' => $e,
				],
			);

      return new JsonResponse([
        'message' => 'Internal server error.',
      ], 500);
    }
  }
}