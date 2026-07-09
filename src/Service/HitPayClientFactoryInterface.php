<?php

declare(strict_types=1);

namespace Drupal\commerce_hitpay\Service;

use Drupal\commerce_payment\Entity\PaymentGatewayInterface;

/**
 * Creates configured HitPay API clients.
 */
interface HitPayClientFactoryInterface {

  /**
   * Creates a HitPay client from a Commerce payment gateway.
   *
   * @param \Drupal\commerce_payment\Entity\PaymentGatewayInterface $paymentGateway
   *   The Commerce payment gateway.
   *
   * @return \Drupal\commerce_hitpay\Service\HitPayClient
   *   Configured HitPay client.
   */
  public function fromPaymentGateway(
    PaymentGatewayInterface $paymentGateway,
  ): HitPayClient;

  /**
	 * Creates a HitPay client directly from credentials.
	 *
	 * Useful for testing connections before configuration is saved.
	 *
	 * @param string $apiKey
	 *   The HitPay API key.
	 * @param bool $sandbox
	 *   Whether to use the HitPay sandbox environment.
	 * @param bool $debug
	 *   Whether debug logging is enabled.
	 *
	 * @return \Drupal\commerce_hitpay\Service\HitPayClient
	 *   The configured HitPay client.
	 */
  public function create(
    string $apiKey,
    bool $sandbox,
		bool $debug = FALSE,
  ): HitPayClient;
}