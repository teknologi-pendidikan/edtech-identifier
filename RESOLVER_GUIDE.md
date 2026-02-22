# EdTech Identifier Resolver System

## Overview

The resolver system enables automatic redirection from EdTech identifiers to their target resources, similar to how DOI resolution works.

## URL Patterns Supported

### 1. Query Parameter Method

```
https://urn.edtech.or.id/resolve.php?id=edtech.journal/2025.001
```

### 2. Path Info Method

```
https://urn.edtech.or.id/resolve/edtech.journal/2025.001
```

### 3. Direct Method (Clean URLs)

```
https://urn.edtech.or.id/edtech.journal/2025.001
```

## How It Works

1. **URL Parsing**: The resolver accepts identifiers in multiple formats
2. **Database Lookup**: Searches the `identifiers` table for matching namespace and suffix
3. **Validation**: Checks that identifier is active and namespace is enabled
4. **Logging**: Records resolution attempt in `identifier_logs` table
5. **Redirect**: Sends HTTP 302 redirect to target URL
6. **Statistics**: Updates resolution count and last accessed time

## Files Created

- **`resolve.php`** - Main resolver script
- **`.htaccess`** - URL rewriting rules for clean URLs
- **`resolver-test.php`** - Testing and debugging interface

## Features

### ✅ Multiple URL Formats

- Query parameters, path info, and clean URLs
- Backward compatibility with different link formats

### ✅ Error Handling

- 404 pages for missing identifiers
- User-friendly error messages
- Graceful fallbacks

### ✅ Analytics & Logging

- Resolution statistics
- IP address and user agent tracking
- Failed resolution attempts logged

### ✅ Security

- Input validation and sanitization
- No direct database access from URLs
- Protection against path traversal

## Testing

1. **Database Test**: Visit `/resolver-test.php` to verify:
   - Required tables exist
   - Sample identifiers available
   - Resolution logic working

2. **Manual Test**: Visit `/resolve.php` for manual identifier lookup

3. **Direct Test**: Create a test identifier and visit it directly:
   ```
   https://urn.edtech.or.id/your.namespace/test.001
   ```

## Configuration

### URL Rewriting

The `.htaccess` file enables clean URLs. Ensure:

- Apache's `mod_rewrite` is enabled
- `.htaccess` files are allowed (`AllowOverride All`)

### Database Schema

Requires tables:

- `identifiers` - Main identifier records
- `namespace_mappings` - Namespace definitions
- `identifier_logs` - Resolution tracking

### Error Pages

Customize the error pages in `resolve.php` functions:

- `show_error_page()` - 404 identifier not found
- `show_resolver_interface()` - Manual lookup form

## API Integration

The resolver can be extended to support content negotiation:

```php
// Example: JSON metadata response
if (strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false) {
    // Return identifier metadata as JSON
    header('Content-Type: application/json');
    echo json_encode($identifier_data);
    exit;
}
```

## Performance Notes

- Database queries use indexes on `namespace_id` and `suffix`
- Resolution logging is asynchronous to avoid slowing redirects
- Caching headers prevent browser caching of redirects

## Integration with Admin System

- Identifiers created in admin interface are immediately resolvable
- Statistics visible in admin dashboard
- Resolution logs help track identifier usage
