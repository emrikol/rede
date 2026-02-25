# Remote EXIF Data Endpoint

A WordPress plugin that exposes a read-only REST API endpoint for retrieving EXIF metadata from remote images. Images are fetched securely through the WordPress HTTP API, validated, and their metadata is returned as clean, JSON-ready data.

## Features

- Fetches remote images via `wp_remote_get()` — respects WordPress proxy settings, SSL, and timeouts
- Validates that the URL points to a real image before reading metadata
- Transforms raw EXIF output into clean, API-friendly data:
  - Rational strings (`"50/1"`) → floats (`50.0`)
  - EXIF timestamps (`"2024:01:15 14:30:22"`) → ISO 8601 (`"2024-01-15T14:30:22"`)
  - GPS degrees/minutes/seconds → decimal degrees (`GPSDecimalLatitude`, `GPSDecimalLongitude`)
  - GPS altitude → signed float (`GPSDecimalAltitude`, negative below sea level)
  - Best-available datetime exposed as `CapturedAt`
  - Binary / control-character values discarded
- Results are cached in WordPress transients to avoid redundant fetches
- Two authentication paths: **WordPress Application Passwords** or **TOTP**

## Requirements

- PHP 8.4+
- WordPress 6.7+
- PHP `exif` extension enabled

## Installation

1. Copy `rede.php` into your `wp-content/plugins/rede/` directory (or drop the file directly into `wp-content/plugins/`).
2. Activate the plugin in the WordPress admin under **Plugins**.
3. Set up authentication (see below) before making API calls.

## Authentication

The endpoint requires authentication on every request. Two methods are supported — use whichever fits your workflow.

### Option 1 — WordPress Application Password

Any logged-in WordPress user authenticates automatically when calling the endpoint via **HTTP Basic auth** using an [Application Password](https://make.wordpress.org/core/2020/11/05/application-passwords-integration-guide/).

Generate one at **Users → Profile → Application Passwords** in the WordPress admin, then include it in requests:

```
Authorization: Basic base64(username:application-password)
```

Example with curl:

```bash
curl -u "admin:xxxx xxxx xxxx xxxx xxxx xxxx" \
  "https://example.com/wp-json/exif-data/v1/read/?url=https%3A%2F%2Fexample.com%2Fphoto.jpg"
```

### Option 2 — TOTP (Time-Based One-Time Password)

A TOTP secret is automatically generated the first time the plugin is activated and stored in WordPress options under `rede_totp_secret`. This lets you authenticate without a WordPress user account, using any RFC 6238-compatible authenticator app (Google Authenticator, Authy, 1Password, etc.).

#### Step 1 — Retrieve your TOTP secret

Run this from WP-CLI:

```bash
wp option get rede_totp_secret
```

Or query it directly in the database:

```sql
SELECT option_value FROM wp_options WHERE option_name = 'rede_totp_secret';
```

The value is a Base32-encoded string, e.g. `JBSWY3DPEHPK3PXP`.

#### Step 2 — Add it to an authenticator app

In your authenticator app, choose **Add account manually** (sometimes called "Enter setup key") and enter:

| Field | Value |
|-------|-------|
| Account name | `rede` (or anything you like) |
| Key / Secret | The Base32 string from Step 1 |
| Type | Time-based |
| Digits | 6 |
| Period | 30 seconds |
| Algorithm | SHA-1 |

#### Step 3 — Make authenticated requests

Pass the current 6-digit code in the `Authorization` header:

```
Authorization: TOTP 123456
```

Example with curl:

```bash
curl -H "Authorization: TOTP 123456" \
  "https://example.com/wp-json/exif-data/v1/read/?url=https%3A%2F%2Fexample.com%2Fphoto.jpg"
```

Codes rotate every 30 seconds. The endpoint accepts the current code plus one window on either side (±30 s) to account for clock skew, giving each code a practical validity window of up to 90 seconds.

#### Rotating the secret

To invalidate all existing TOTP sessions and generate a fresh secret, delete the option:

```bash
wp option delete rede_totp_secret
```

A new secret will be generated automatically on the next request.

## API Reference

### `GET /wp-json/exif-data/v1/read/`

Returns EXIF metadata for a remote image URL.

**Query parameters**

| Parameter | Required | Description |
|-----------|----------|-------------|
| `url` | Yes | URL of the remote image. Percent-encoding is handled automatically. |

**Success response** `200 OK`

```json
{
  "success": true,
  "data": {
    "Make": "Canon",
    "Model": "EOS 5D Mark IV",
    "ExposureTime": 0.005,
    "FNumber": 2.8,
    "ISOSpeedRatings": 400,
    "DateTimeOriginal": "2024-01-15T14:30:22",
    "CapturedAt": "2024-01-15T14:30:22",
    "GPSLatitude": [48.0, 51.0, 30.0],
    "GPSLatitudeRef": "N",
    "GPSLongitude": [2.0, 21.0, 5.0],
    "GPSLongitudeRef": "E",
    "GPSDecimalLatitude": 48.858333,
    "GPSDecimalLongitude": 2.351389,
    "GPSAltitude": 35.0,
    "GPSDecimalAltitude": 35.0
  }
}
```

**Error responses**

| Condition | `success` | `data` |
|-----------|-----------|--------|
| Not authenticated | — | `401 Unauthorized` |
| Network failure fetching image | `false` | Error message from `wp_remote_get()` |
| Non-2xx HTTP response | `false` | `"Remote image returned HTTP 404."` |
| Empty response body | `false` | `"Remote image returned an empty body."` |
| URL is not a valid image | `false` | `"URL does not point to a valid image."` |
| `exif` extension missing | `false` | `"exif_read_data function not found!"` |
| No EXIF data in image | `false` | `"EXIF Not Found for image"` |

## Development

Install dependencies:

```bash
composer install
```

Run the test suite:

```bash
composer test
```

Run tests with line-level coverage (requires Xdebug):

```bash
composer test:coverage
```

Lint:

```bash
composer lint
composer lint:fix
```

## License

GPL-3.0-or-later. See plugin header in `rede.php`.
