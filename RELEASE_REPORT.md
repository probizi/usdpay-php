# USDPAY PHP SDK 1.0.0 Release Report

Status: implementation and local validation complete. Repository publication and the `v1.0.0` tag are intentionally pending owner approval.

| Check | Result |
| --- | --- |
| PHP 8.1.34 | PASS |
| PHP 8.2.31 | PASS |
| PHP 8.3.31 | PASS |
| PHP 8.4.21 | PASS |
| `composer validate --strict` | PASS |
| `composer install` | PASS |
| `composer dump-autoload -o` | PASS |
| `composer audit --locked` | PASS — no known security advisories |
| PSR-12 | PASS — PHP_CodeSniffer 4.0.4 |
| PHPUnit | PASS — 17 tests, 54 assertions on every PHP version |
| Secret scan | PASS — Gitleaks 8.30.1 and targeted credential patterns |

All PHP runtime archives, Composer and Gitleaks were checksum-verified before use. No production API requests are made by the unit tests.
