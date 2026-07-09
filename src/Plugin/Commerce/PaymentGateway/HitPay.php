<?php

declare(strict_types=1);

namespace Drupal\commerce_hitpay\Plugin\Commerce\PaymentGateway;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\commerce_payment\Attribute\CommercePaymentGateway;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OffsitePaymentGatewayBase;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\SupportsRefundsInterface;
use Drupal\commerce_hitpay\PluginForm\HitPayOffsiteForm;

use Symfony\Component\DependencyInjection\ContainerInterface;

use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_price\Price;
use Drupal\commerce_payment\Exception\PaymentGatewayException;

use Drupal\commerce_hitpay\Exception\HitPayException;
use Drupal\commerce_hitpay\Service\HitPayClientFactoryInterface;
use Drupal\Core\Url;

use Drupal\Core\Entity\EntityStorageException;
use Drupal\commerce_hitpay\Service\HitPayClient;


/**
 * Provides the HitPay payment gateway.
 */
#[CommercePaymentGateway(
  id: "hitpay",
  label: new TranslatableMarkup("HitPay"),
  display_label: new TranslatableMarkup("HitPay"),
  forms: [
    "offsite-payment" => HitPayOffsiteForm::class,
  ],
)]

class HitPay extends OffsitePaymentGatewayBase implements SupportsRefundsInterface {
	
	/**
	 * The HitPay client factory.
	 *
	 * @var \Drupal\commerce_hitpay\Service\HitPayClientFactoryInterface
	 */
	protected HitPayClientFactoryInterface $clientFactory;
	
   /**
   * {@inheritdoc}
   */
	public static function create(
	  ContainerInterface $container,
	  array $configuration,
	  $plugin_id,
	  $plugin_definition,
	): static {
	  /** @var static $instance */
	  $instance = parent::create(
			$container,
			$configuration,
			$plugin_id,
			$plugin_definition,
	  );

	  $instance->clientFactory = $container->get(
			HitPayClientFactoryInterface::class,
	  );

	  return $instance;
	}

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return [
      'api_key' => '',
      'debug' => FALSE,
      'webhook_id' => '',
      'webhook_salt' => '',
    ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state): array {
    $form = parent::buildConfigurationForm($form, $form_state);

    $form['api_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('API Key'),
      '#default_value' => $this->configuration['api_key'] ?? '',
      '#required' => TRUE,
    ];

    $form['debug'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable debug logging'),
      '#default_value' => !empty($this->configuration['debug']),
    ];

    return $form;
  }
  
    /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state): void {
		parent::validateConfigurationForm($form, $form_state);
		
    if ($form_state->isSubmitted()) {

      try {
				$values = $form_state->getValue($form['#parents']);
		  
        $client = $this->clientFactory->create(
          trim($values['api_key']),
          $values['mode'] === 'test',
					!empty($values['debug'])
        );

        if (!$client->testConnection()) {
          $form_state->setErrorByName(
            'api_key',
            $this->t('Unable to connect to HitPay using the supplied credentials.')
          );
        }
      }
      catch (HitPayException $e) {
        $form_state->setErrorByName(
          'api_key',
          $e->getMessage()
        );
      }
    }
  }
  
  /**
  * {@inheritdoc}
  */
  public function setConfiguration(array $configuration) {
    // Force the background properties to retain their values if passed from database storage
    parent::setConfiguration($configuration);
    
    // Explicitly sync the object property map
    $this->configuration['webhook_id'] = $configuration['webhook_id'] ?? '';
    $this->configuration['webhook_salt'] = $configuration['webhook_salt'] ?? '';
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state): void {
		$currentApiKey = $this->configuration['api_key'] ?? '';
		$currentMode = $this->configuration['mode'] ?? '';
		
		$currentWebhookId = $this->configuration['webhook_id'] ?? '';
    $currentWebhookSalt = $this->configuration['webhook_salt'] ?? '';
	  
		parent::submitConfigurationForm($form, $form_state);
	
    if (!$form_state->getErrors()) {
			$form_object = $form_state->getFormObject();
	  
	    if (!method_exists($form_object, 'getEntity')) {
				return;
			}

			$gateway_entity = $form_object->getEntity();

			if (!$gateway_entity) {
				return;
			}
	  
			$paymentGatewayId = $gateway_entity->id();
			if (empty($paymentGatewayId)) {
				$form_state->setErrorByName(
				'api_key',
				$this->t('Unable to determine the payment gateway ID.')
				);
				return;
			}
	  
      // Get the correct array parent path values safely
      $values = $form_state->getValue($form['#parents']);
	  
			$newApiKey = trim($values['api_key'] ?? '');
			$newMode = $values['mode'] ?? 'test';
	  
			$credentialsChanged =
				$currentApiKey !== $newApiKey ||
				$currentMode !== $newMode;
		  
			$this->configuration['api_key'] = $newApiKey;
			$this->configuration['mode'] = $newMode;
			$this->configuration['debug'] = !empty($values['debug']);
	  
			$this->configuration['webhook_id'] = $currentWebhookId;
      $this->configuration['webhook_salt'] = $currentWebhookSalt;
	  
			if (
				$credentialsChanged &&
				!empty($this->configuration['webhook_id']) &&
				!empty($currentApiKey)
			) {
				$this->deleteWebhookEvent(
					$this->configuration['webhook_id'],
					$currentApiKey,
					$currentMode,
					!empty($this->configuration['debug']),
					TRUE
				);
		  }
		
			if (!empty($this->configuration['api_key'])) {
			
				$client = $this->clientFactory->create(
					$this->configuration['api_key'],
					$this->configuration['mode'] === 'test',
					!empty($this->configuration['debug'])
				);

				$webhookUrl = Url::fromRoute(
					'commerce_hitpay.webhook',
					['commerce_payment_gateway' => $paymentGatewayId],
					['absolute' => TRUE]
				)->toString();
				
				try {
					
					// Always synchronize the webhook when the gateway configuration is
					// saved. This repairs webhooks that were deleted externally and keeps
					// the registered endpoint URL in sync with the site configuration.
					
					$this->ensureWebhookEvent(
						$client, 
						$webhookUrl, 
						$this->configuration['webhook_id'] ?? ''
					);

					if (
						empty($this->configuration['webhook_id']) ||
						empty($this->configuration['webhook_salt'])
					) {
						
						if (empty($this->configuration['webhook_id'])) {
							$this->ensureWebhookEvent(
								$client, 
								$webhookUrl
							);
						} elseif (empty($this->configuration['webhook_salt'])) { 

							$this->deleteWebhookEvent(
								$this->configuration['webhook_id'],
								$this->configuration['api_key'],
								$this->configuration['mode'],
								!empty($this->configuration['debug'])
							);
							
							$this->ensureWebhookEvent(
								$client, 
								$webhookUrl,
							);
						}
						
						if (
							empty($this->configuration['webhook_id']) ||
							empty($this->configuration['webhook_salt'])
						) {
							throw new HitPayException(
								'HitPay webhook synchronization completed without usable webhook credentials.'
							);
						}
					}
				}
				catch (HitPayException  $e) {
					\Drupal::logger('commerce_hitpay')->error('Webhook synchronization failed: @message', [
						'@message' => $e->getMessage(),
					]);
					$form_state->setErrorByName('api_key', $this->t('Failed to register webhook with HitPay API.'));
				}
			}
    }
  }
	
	private function ensureWebhookEvent(
		HitPayClient $client,
		string $webhookUrl,
		?string $webhookId = NULL
	) {
		$webhook = $client->registerWebhook(
			$webhookUrl,
			$webhookId
		);

		if (!empty($webhook['id'])) {
			$this->configuration['webhook_id'] = (string) $webhook['id'];
		}

		if (!empty($webhook['salt'])) {
			$this->configuration['webhook_salt'] = (string) $webhook['salt'];
		}
	}
	
	private function deleteWebhookEvent(
		string $webhookId,
		string $apiKey,
		string $mode,
		bool $debug,
		bool $ignoreFailure = FALSE
	) {
		$client = $this->clientFactory->create(
			$apiKey,
			$mode === 'test',
			$debug
		);
		
		try {
			$client->deleteWebhook($webhookId);
		}
		catch (HitPayException $e) {
			// A missing remote webhook is equivalent to successful deletion.
			if ($e->getCode() !== 404 && !$ignoreFailure) {
				throw $e;
			}

			if ($e->getCode() !== 404) {
				\Drupal::logger('commerce_hitpay')->warning(
					'Unable to delete previous HitPay webhook @webhook_id: @message',
					[
						'@webhook_id' => $webhookId,
						'@message' => $e->getMessage(),
					]
				);
			}
		}

		$this->configuration['webhook_id'] = '';
		$this->configuration['webhook_salt'] = '';
	}
  
   /**
   * {@inheritdoc}
   */
  public function refundPayment(PaymentInterface $payment, ?Price $amount = NULL): void {
    try {
			$this->assertPaymentState($payment, [
				'completed',
				'partially_refunded',
			]);
		}
		catch (\InvalidArgumentException $e) {
			throw new PaymentGatewayException(
				(string) $this->t(
					'The payment cannot be refunded because it is in the "@state" state.',
					[
						'@state' => $payment->getState()->getId(),
					]
				),
				0,
				$e
			);
		}

    $amount = $amount ?: $payment->getAmount();
    try {
			$this->assertRefundAmount($payment, $amount);
		}
		catch (\InvalidArgumentException $e) {
			throw new PaymentGatewayException(
				(string) $this->t(
					'The requested refund amount is invalid or exceeds the remaining refundable amount.'
				),
				0,
				$e
			);
		}

    $configuration = $this->getConfiguration();
    $remoteId = $payment->getRemoteId();

		if (empty($remoteId)) {
			throw new PaymentGatewayException(
				(string) $this->t('This transaction cannot be refunded because the remote HitPay ID is missing.')
			);
		}

    try {
      $client = $this->clientFactory->create(
        $configuration['api_key'],
        $configuration['mode'] === 'test',
				!empty($configuration['debug'])
      );

      $refundAmount = number_format((float) $amount->getNumber(), 2, '.', '');

      $response = $client->createRefund((string) $remoteId, $refundAmount);
			
			if (
				empty($response['id']) ||
				empty($response['status'])
			) {
				throw new PaymentGatewayException(
					(string) $this->t('HitPay returned an invalid refund response.')
				);
			}
			
			$old_refunded_amount = $payment->getRefundedAmount();
      $new_refunded_amount = $old_refunded_amount->add($amount);
      $payment->setRefundedAmount($new_refunded_amount);

      if ($new_refunded_amount->equals($payment->getAmount())) {
        $payment->setState('refunded');
      } 
      else {
        $payment->setState('partially_refunded');
      }
			
			try {
				$payment->save();
			}
			catch (EntityStorageException $e) {
				\Drupal::logger('commerce_hitpay')->critical(
					'HitPay refund @refund_id succeeded remotely, but Commerce payment @payment_id could not be updated: @message',
					[
						'@refund_id' => (string) $response['id'],
						'@payment_id' => $payment->id(),
						'@message' => $e->getMessage(),
					]
				);

				throw new PaymentGatewayException(
					(string) $this->t(
						'The refund was successfully created by HitPay, but Drupal Commerce could not update the payment record. Do not retry the refund. Please verify the refund in the HitPay dashboard and contact the site administrator.'
					),
					0,
					$e
				);
			}
	  
			$order = $payment->getOrder();
			
			if (!$order) {
				\Drupal::logger('commerce_hitpay')->critical(
					'HitPay refund @refund_id succeeded remotely and Commerce payment @payment_id was updated, but the associated Commerce order could not be loaded.',
					[
						'@refund_id' => (string) $response['id'],
						'@payment_id' => $payment->id(),
					]
				);

				throw new PaymentGatewayException(
					(string) $this->t(
						'The refund was successfully created by HitPay and the Commerce payment was updated, but the associated Commerce order could not be loaded. Do not retry the refund. Please contact the site administrator.'
					)
				);
			}
	  
			$refunds = $order->getData('hitpay_refunds');
				
			if (!is_array($refunds)) {
				$refunds = [];
			}
			
			$payment_id = (string) $payment->id();
			
			if (!isset($refunds[$payment_id]) || !is_array($refunds[$payment_id])) {
				$refunds[$payment_id] = [];
			}

			$refunds[$payment_id][] = [
				'id' => (string) $response['id'],
				'status' => (string) $response['status'],
				'amount' => $amount->getNumber(),
				'currency' => $amount->getCurrencyCode(),
				'created' => time(),
			];
			
			$order->setData('hitpay_refunds', $refunds);
			$order->setData('hitpay_get_payment_force', TRUE);
			
			try {
				$order->save();
			}
			catch (EntityStorageException $e) {
				\Drupal::logger('commerce_hitpay')->critical(
					'HitPay refund @refund_id succeeded remotely and Commerce payment @payment_id was updated, but refund metadata could not be saved to order @order_id: @message',
					[
						'@refund_id' => (string) $response['id'],
						'@payment_id' => $payment->id(),
						'@order_id' => $order->id(),
						'@message' => $e->getMessage(),
					]
				);

				throw new PaymentGatewayException(
					(string) $this->t(
						'The refund was successfully created by HitPay and the Commerce payment was updated, but the HitPay refund history could not be saved to the order. Do not retry the refund. Please contact the site administrator.'
					),
					0,
					$e
				);
			}
			
			\Drupal::logger('commerce_hitpay')->info(
				'Refund @refund_id for @amount successfully created for payment @payment_id.',
				[
					'@refund_id' => (string) $response['id'],
					'@amount' => $amount->__toString(),
					'@payment_id' => $remoteId,
				]
			);
		}
    catch (HitPayException $e) {
			\Drupal::logger('commerce_hitpay')->error(
				'Remote refund processing failed on HitPay server: @message',
				[
					'@message' => $e->getMessage(),
				]
			);

			throw new PaymentGatewayException(
				(string) $this->t(
					'HitPay refund failed: @message',
					[
						'@message' => $e->getMessage(),
					]
				),
				0,
				$e
			);
		}
  }																		
}