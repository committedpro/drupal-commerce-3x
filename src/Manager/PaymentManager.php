<?php

declare(strict_types=1);

namespace Drupal\commerce_hitpay\Manager;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Manages Commerce payments for HitPay.
 */
final class PaymentManager {
	
	private const PAYMENT_STATE_COMPLETED = 'completed';

  /**
   * Constructs a new PaymentManager object.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Determines whether a payment has already been processed.
   */
  public function paymentExists(string $remoteId): bool {
    $payments = $this->entityTypeManager
      ->getStorage('commerce_payment')
      ->loadByProperties([
        'remote_id' => $remoteId,
      ]);

    return !empty($payments);
  }

 /**
 * Creates a completed Commerce payment.
 *
 * @throws \Drupal\Core\Entity\EntityStorageException
 */
  public function createCompletedPayment(
    OrderInterface $order,
    string $remoteId,
    string $paymentGatewayId,
    string $remoteState = self::PAYMENT_STATE_COMPLETED,
  ): PaymentInterface {

    /** @var \Drupal\commerce_payment\Entity\PaymentInterface $payment */
    $payment = $this->entityTypeManager
			->getStorage('commerce_payment')
			->create([
				'state' => self::PAYMENT_STATE_COMPLETED,
				'amount' => $order->getBalance(),
				'payment_gateway' => $paymentGatewayId,
				'order_id' => $order->id(),
				'remote_id' => $remoteId,
				'remote_state' => $remoteState,
				'completed' => time(),
			]);

    $payment->save();
	
		/** @var \Drupal\commerce_order\Entity\OrderInterface $fresh_order */
    $fresh_order = $this->entityTypeManager
      ->getStorage('commerce_order')
      ->load($order->id());
	  
		if ($fresh_order) {
			$fresh_order->setData(
				'hitpay_payment_id',
				(string) $remoteId
			);
			$fresh_order->setData(
				'hitpay_payment_status',
				(string) $remoteState
			);
			
			if ($fresh_order->getState()->getId() === 'draft') {
				$fresh_order->getState()->applyTransitionById('place');
			}

			if ($fresh_order->isLocked()) {
				$fresh_order->unlock();
			}
			
      $fresh_order->save();
    }
    return $payment;
  }
}