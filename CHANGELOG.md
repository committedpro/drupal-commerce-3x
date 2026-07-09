# Changelog

All notable changes to the HitPay Payment Gateway for Drupal Commerce 3.x will be documented in this file.

## [1.0.0] - 2026-07-06

### Added

* Initial release of the HitPay Payment Gateway integration for Drupal Commerce 3.x.
* Support for Drupal 10.3 or later.
* Support for Drupal 11.
* Support for PHP 8.2 or later.
* HitPay Hosted Checkout integration.
* Support for HitPay Sandbox and Live environments.
* HitPay API credential validation during payment gateway configuration.
* Automatic HitPay webhook registration and lifecycle management.
* Automatic webhook synchronization when the payment gateway configuration is saved.
* Automatic recovery of missing or externally deleted webhooks.
* Cleanup of duplicate webhooks registered for the same Drupal webhook endpoint.
* Automatic webhook recreation when the locally stored webhook salt is missing.
* Webhook synchronization when the Drupal webhook endpoint changes.
* Cleanup of the previous webhook when API credentials or environment change.
* Secure webhook signature verification using the HitPay webhook salt.
* HitPay payment notification processing through verified webhooks.
* Protection against duplicate Commerce payment creation for repeated payment notifications.
* Customer return handling after HitPay Hosted Checkout.
* Full and partial refund support through the HitPay Refund API.
* Commerce payment state and refunded amount synchronization after successful refunds.
* Storage of available HitPay refund metadata on Commerce orders.
* Critical error logging when a remote refund succeeds but local Commerce payment or order updates cannot be persisted.
* Optional debug logging for sanitized HitPay API requests, responses, HTTP status codes, and request failures.
* Redaction of sensitive values and customer information from debug logs.
