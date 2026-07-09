<?php

declare(strict_types=1);

namespace Drupal\commerce_hitpay\PluginForm;

use Drupal\commerce_hitpay\Service\HitPayClientFactoryInterface;
use Drupal\commerce_payment\Exception\PaymentGatewayException;
use Drupal\commerce_payment\PluginForm\PaymentOffsiteForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\commerce_order\Entity\OrderInterface;

/**
 * Redirects customers to HitPay with concurrent thread lock protection.
 */
final class HitPayOffsiteForm extends PaymentOffsiteForm {
	
	use StringTranslationTrait;
	
	/**
   * The HitPay client factory.
   *
   * @var \Drupal\commerce_hitpay\Service\HitPayClientFactoryInterface
   */
  protected $clientFactory;

  /**
   * The lock backend.
   *
   * @var \Drupal\Core\Lock\LockBackendInterface
   */
  protected $lock;
  
  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;
	/**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state): array {
	$form = parent::buildConfigurationForm($form, $form_state);
	
	/** @var \Drupal\commerce_hitpay\Service\HitPayClientFactoryInterface $client_factory */
		$this->clientFactory = \Drupal::service(HitPayClientFactoryInterface::class);

		/** @var \Drupal\Core\Lock\LockBackendInterface $lock */
		$this->lock = \Drupal::service('lock');

		/** @var \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager */
		$this->entityTypeManager = \Drupal::entityTypeManager();
    /** @var \Drupal\commerce_payment\Entity\PaymentInterface $payment */
    $payment = $this->entity;
		
    $order = $payment->getOrder();
		
		$paymentRequestId = (string) $order->getData('hitpay_payment_request_id');
    $allowRetry = (bool) $order->getData('hitpay_allow_retry');

    // Layer 1: Fast-fail bypass for background polling loops (unlocked read).
    if ($paymentRequestId !== '' && !$allowRetry) {
      return $this->buildProcessingForm($form, $order);
    }
	
    $lockName = 'commerce_hitpay.payment_request.' . $order->id();

    if (!$this->lock->acquire($lockName, 30.0)) {
      return $this->buildWaitingForm($form, $order);
		}
		
		try {
      /** @var \Drupal\commerce_order\Entity\OrderInterface|null $order */
      $order = $this->entityTypeManager
        ->getStorage('commerce_order')
        ->loadUnchanged($order->id());

      if (!$order) {
        throw new PaymentGatewayException('Unable to reload the Commerce order context.');
      }

      // CRITICAL FIX: Refresh variables from the fresh database state inside the lock.
      $paymentRequestId = (string) $order->getData('hitpay_payment_request_id');
      $allowRetry = (bool) $order->getData('hitpay_allow_retry');
			
			// Layer 2: Lock double-check guard. Did a thread write a token while we waited?
      if ($paymentRequestId !== '' && !$allowRetry) {
        return $this->buildProcessingForm($form, $order);
      }
			
			$paymentGateway = $payment->getPaymentGateway();
      $amount = $order->getBalance();

      $returnUrl = Url::fromRoute(
        'commerce_hitpay.return',
        [
          'commerce_payment_gateway' => $paymentGateway->id(),
          'hitpay_order_id' => $order->uuid(),
        ],
        ['absolute' => TRUE]
      )->toString();

      $billingProfile = $order->getBillingProfile();
      $email = $order->getEmail() ?? '';
      $name = '';

      if ($billingProfile) {
        /** @var \Drupal\address\AddressInterface $address */
        $address = $billingProfile->get('address')->first();
        if ($address) {
          $name = trim($address->getGivenName() . ' ' . $address->getFamilyName());
        }
      }

      $payload = [
        'amount' => number_format((float) $amount->getNumber(), 2, '.', ''),
        'currency' => $amount->getCurrencyCode(),
        'email' => $email,
        'name' => $name,
        'reference_number' => $order->uuid(),
        'redirect_url' => $returnUrl,
      ];

      $client = $this->clientFactory->fromPaymentGateway($paymentGateway);

      try {
        $response = $client->createPaymentRequest($payload);
      }
      catch (\Throwable $e) {
				throw new PaymentGatewayException(
				'Unable to create the HitPay payment request.',
				0,
				$e
				);
			}
			
			if (empty($response['id']) || empty($response['url'])) {
				throw new PaymentGatewayException('Unable to create the HitPay payment request.');
			}
			
			$order->setData(
				'hitpay_payment_request_id',
				(string) $response['id']
			);

			$order->setData(
				'hitpay_payment_status',
				(string) ($response['status'] ?? 'pending')
			);
			
			$order->setData('hitpay_allow_retry', FALSE);
		
			$order->save();
			
			return $this->buildRedirectForm(
				$form,
				$form_state,
				$response['url'],
				[],
				self::REDIRECT_GET
			);
		}
    finally {
      $this->lock->release($lockName);
    }
  }
	
	 /**
   * Renders the verification loop polling interface wrapper.
   */
  private function buildProcessingForm(
		array $form, 
		OrderInterface $order
	): array {
		$paymentRequestId = (string) $order->getData(
			'hitpay_payment_request_id'
		);
	
		// 1. Active Verification View (Hidden by JS when timeout hits)
    $form['processing_text'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['messages', 'messages--status', 'hitpay-processing-message'],
      ],
      'message' => [
        '#markup' => $this->t('Your payment transaction is currently being verified by HitPay. This page will automatically update once confirmed. Please do not close this window.'),
      ],
    ];
	
		// 2. Timeout Fallback View (Hidden by default, exposed by JS when timeout hits)
    $form['timeout_fallback'] = [
      '#type' => 'container',
      '#attributes' => [
        'id' => 'hitpay-timeout-fallback',
        'class' => ['messages', 'messages--warning'],
        'hidden' => 'hidden',
      ],
      'heading' => [
        '#markup' => '<strong>' . $this->t('Payment confirmation is taking longer than expected') . '</strong><br />',
      ],
      'message' => [
        '#markup' => $this->t(
          'Your payment confirmation is taking longer than expected. 
		   Please do not make another payment. 
		   Contact the merchant for assistance'
        ),
      ],
      'meta_details' => [
        '#type' => 'item',
        '#markup' => '<p style="margin-top: 1em; font-family: monospace;">' . 
          $this->t('Payment Reference: @reference', ['@reference' => $paymentRequestId]) . '<br />' .
          $this->t('Order Reference: @uuid', ['@uuid' => $order->uuid()]) . 
          '</p>',
      ],
    ];
    
    // Attach your secure asset library
    $form['#attached']['library'][] = 'commerce_hitpay/processing';
	
		$form['#attached']['drupalSettings']['commerceHitPay'] = [
			'paymentRequestId' => $paymentRequestId,
		];
		
    return $form;
  }
  
    /**
	 * Renders a short waiting view while another request creates
	 * the HitPay payment request.
	 */
	private function buildWaitingForm(
	  array $form,
	  OrderInterface $order
	): array {
	  $form['waiting_text'] = [
			'#type' => 'container',
			'#attributes' => [
				'class' => [
				'messages',
				'messages--status',
				'hitpay-waiting-message',
				],
			],
			'message' => [
				'#markup' => $this->t(
				'Your payment request is being prepared. Please wait.'
				),
			],
	  ];

	  $form['waiting_fallback'] = [
			'#type' => 'container',
			'#attributes' => [
				'id' => 'hitpay-waiting-fallback',
				'class' => [
				'messages',
				'messages--warning',
				],
				'hidden' => 'hidden',
			],
			'heading' => [
				'#markup' => '<strong>' .
				$this->t('Payment request preparation is taking longer than expected') .
				'</strong><br />',
			],
			'message' => [
				'#markup' => $this->t(
				'We could not finish preparing your payment request within the expected time. Please refresh this page to try again. If the problem continues, contact the merchant for assistance.'
				),
			],
			'meta_details' => [
				'#type' => 'item',
				'#markup' =>
				'<p style="margin-top: 1em; font-family: monospace;">' .
				$this->t(
					'Order Reference: @uuid',
					['@uuid' => $order->uuid()]
				) .
				'</p>',
			],
	  ];

	  $form['#attached']['library'][] = 'commerce_hitpay/waiting';

	  $form['#attached']['drupalSettings']['commerceHitPayWaiting'] = [
			'orderId' => $order->uuid(),
	  ];

	  return $form;
	}
}