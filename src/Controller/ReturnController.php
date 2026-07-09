<?php

declare(strict_types=1);

namespace Drupal\commerce_hitpay\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Drupal\Core\Url;
use Drupal\commerce_order\Entity\OrderInterface;

/**
 * Handles the customer return from HitPay.
 */
final class ReturnController extends ControllerBase {

	/**
	 * Customer return endpoint.
	*/
	public function handleReturn(Request $request): RedirectResponse {
		$hitpay_order_id = self::sanitizeField($request->query->get('hitpay_order_id'));
		
		if ($hitpay_order_id === '') {
		  $this->messenger()->addError($this->t('400: Invalid order reference received. Unable to locate your order.'));
		  return $this->redirectToFront();
		}
		
		$orders = $this->entityTypeManager()
			->getStorage('commerce_order')
			->loadByProperties([
				'uuid' => $hitpay_order_id,
			]);
		  
		if (empty($orders)) {
		  $this->messenger()->addError($this->t('400: Invalid order reference received. Unable to locate your order.'));
		  return $this->redirectToFront();
		}
		  
		/** @var \Drupal\commerce_order\Entity\OrderInterface $order */
		$order = reset($orders);

		if (!$order) {
		  $this->messenger()->addError($this->t('400: Invalid order reference received. Unable to locate your order.'));
		  return $this->redirectToFront();
		}
		
		$hitpay_payment_request_id = (string) $order->getData('hitpay_payment_request_id');

		$reference = self::sanitizeField($request->query->get('reference'));
		
		if (
			$reference === '' || 
			$reference !== $hitpay_payment_request_id
		) {
		  $this->messenger()->addError($this->t('400: Invalid payment reference received. Unable to locate your order.'));
		  return $this->redirectToFront();
		}

		$status = self::sanitizeField($request->query->get('status'));
		
		$orderStatus = $order->getState()->getId();

		if ($orderStatus === 'completed') {
		  $this->messenger()->addStatus(
			$this->t('Your payment is complete!')
		  );

		  return $this->redirectCheckoutForm($order, 'complete');
		}
		
		if ($status === 'canceled') {
			$order->setData('hitpay_allow_retry', TRUE);
			$order->setData('hitpay_payment_status', $status);
			$order->save();
			
			$this->messenger()->addError($this->t('You canceled the payment.'));
			
			return $this->redirectCheckoutForm($order, 'review');
		} elseif ($status === 'failed') {
			$order->setData('hitpay_allow_retry', TRUE);
			$order->setData('hitpay_payment_status', $status);
			$order->save();
			
			$this->messenger()->addError($this->t('Your payment failed.'));

			return $this->redirectCheckoutForm($order, 'review');
		} elseif ($status === 'completed' || $status === 'pending') {
			$order->setData('hitpay_payment_status', $status);
			$order->setData('hitpay_allow_retry', FALSE);
			$order->save();

			$this->messenger()->addStatus(
			  $this->t('Your payment is being processed. This page will update once HitPay confirms the payment.')
			);
			
			return $this->redirectCheckoutForm($order, 'payment');
		} else {
			// Unknown status.
			//
			// Do not permit another payment attempt because the current HitPay
			// payment request may still reach a successful terminal state.
			$order->setData('hitpay_allow_retry', FALSE);
																	 
			$order->setData('hitpay_payment_status', $status);
			$order->save();
			
			$this->messenger()->addError($this->t('The payment status could not be confirmed. Please do not make another payment.'));
			
			return $this->redirectCheckoutForm($order, 'payment');
		}
	}
  private static function sanitizeField(?string $value): string {
		return trim((string) $value);
  }
  
  private function redirectToFront(): RedirectResponse {
	  return new RedirectResponse(
			Url::fromRoute('<front>')->toString()
	  );
  }
  
  private function redirectCheckoutForm(
		OrderInterface $order, string $step
  ): RedirectResponse {
	  return new RedirectResponse(
		Url::fromRoute(
			'commerce_checkout.form',
			[
				'commerce_order' => $order->id(),
				'step' => $step
			],
			['absolute' => TRUE]
			)->toString()
	  );
  }
}