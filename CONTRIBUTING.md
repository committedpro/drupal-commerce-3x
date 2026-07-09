# Contributing to HitPay Payment Gateway for Drupal Commerce

Thank you for your interest in contributing to the HitPay Payment Gateway for Drupal Commerce.

Contributions are welcome, including bug fixes, improvements, documentation updates, tests, and other changes that improve the module.

Before contributing, please review the guidelines below.

## Coding Standards

All contributions should follow Drupal coding standards and modern PHP best practices.

Contributors should:

* Follow Drupal coding standards.
* Maintain compatibility with the supported Drupal, Drupal Commerce, and PHP versions.
* Use dependency injection instead of directly accessing Drupal services where practical.
* Add appropriate PHP type declarations.
* Add PHPDoc comments where required.
* Keep changes focused on the purpose of the pull request.
* Avoid unrelated code formatting or refactoring.
* Never commit API keys, webhook salts, payment information, customer information, or other sensitive data.

Before submitting changes, run Drupal Coder and PHP_CodeSniffer when available.

Example:

```bash
phpcs --standard=Drupal,DrupalPractice web/modules/custom/commerce_hitpay
```

Fix coding standard violations where possible:

```bash
phpcbf --standard=Drupal,DrupalPractice web/modules/custom/commerce_hitpay
```

## Running Tests

Before submitting a pull request, test the module with a supported Drupal Commerce installation.

At minimum, verify the functionality affected by your changes.

Depending on the change, testing may include:

* Module installation and uninstallation.
* Payment gateway configuration.
* HitPay API credential validation.
* Sandbox and Live environment selection.
* Hosted Checkout redirection.
* Customer return handling.
* Webhook registration and synchronization.
* Webhook signature verification.
* Successful payment processing.
* Duplicate webhook handling.
* Commerce payment creation.
* Full refunds.
* Partial refunds.
* Commerce payment state transitions.
* Error handling and logging.

Use HitPay Sandbox credentials when testing payment and refund workflows.

Do not use production API credentials or real customer payment information when testing contributions.

If automated tests are available, run the relevant test suite before submitting the pull request.

## Submitting Pull Requests

Before submitting a pull request:

1. Fork the repository.
2. Create a new branch from the latest `main` branch.
3. Make focused changes related to a single bug, feature, or improvement.
4. Follow the coding standards described above.
5. Test the affected functionality.
6. Update documentation when behavior, configuration, installation, or public APIs change.
7. Update `CHANGELOG.md` when appropriate.
8. Commit your changes with clear and meaningful commit messages.
9. Push the branch to your fork.
10. Open a pull request against the `main` branch.

A pull request should include:

* A clear description of the change.
* The reason for the change.
* Steps to test the change.
* Drupal version used for testing.
* Drupal Commerce version used for testing.
* PHP version used for testing.
* HitPay environment used for testing, when applicable.
* Relevant logs or screenshots, when helpful.

Do not include API keys, webhook salts, customer information, payment information, or other sensitive data in pull requests, screenshots, logs, or test fixtures.

## Reporting Issues

Before reporting an issue:

* Check existing issues to avoid creating duplicates.
* Verify that you are using a supported Drupal, Drupal Commerce, and PHP version.
* Test with the latest available module release when possible.
* Review Drupal logs for relevant error messages.

When reporting an issue, include:

* A clear description of the problem.
* Steps to reproduce the problem.
* Expected behavior.
* Actual behavior.
* Drupal version.
* Drupal Commerce version.
* PHP version.
* Module version or Git commit.
* HitPay environment (Sandbox or Live), when applicable.
* Relevant error messages and logs.

Never include API keys, webhook salts, customer payment information, personal customer data, or other sensitive credentials in public issue reports.

## Security Issues

Do not report security vulnerabilities through public GitHub issues.

If you believe you have discovered a security vulnerability, report it privately to the project maintainers or through the security reporting process documented by the project.

Do not publicly disclose the vulnerability until the maintainers have had reasonable time to investigate and address the issue.

## License

By contributing to this project, you agree that your contributions will be licensed under the same license as the project: GNU General Public License v2 or later (`GPL-2.0-or-later`).
