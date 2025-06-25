# DKAN Importer API

This module provides a REST API endpoint for uploading CSV files with basic authentication.

## Features

- Single POST endpoint for CSV file uploads
- Basic authentication for security
- Files saved to public files folder (`public://imports/`)
- Returns file path and metadata to the client
- Comprehensive error handling and logging
- File validation (CSV files only)

## Installation

1. Enable the module: `drush en dkan_importer_api`
2. Ensure the `basic_auth` module is enabled
3. Clear cache: `drush cr`

## Configuration

After installation, you need to:

1. Grant the "Upload CSV files" permission to appropriate user roles
2. Ensure users have valid credentials for basic authentication

## API Usage

### Endpoint
```
POST /api/importer/upload
```

### Authentication
Uses HTTP Basic Authentication. Include credentials in the request header:
```
Authorization: Basic <base64-encoded-credentials>
```

### Request
- Method: POST
- Content-Type: multipart/form-data
- Body: Include the CSV file in a form field

### Example using cURL
```bash
curl -X POST \
  http://your-drupal-site.com/api/importer/upload \
  -H "Authorization: Basic $(echo -n 'username:password' | base64)" \
  -F "file=@path/to/your/file.csv"
```

### Example using JavaScript
```javascript
const formData = new FormData();
formData.append('file', csvFile);

fetch('/api/importer/upload', {
  method: 'POST',
  headers: {
    'Authorization': 'Basic ' + btoa('username:password')
  },
  body: formData
})
.then(response => response.json())
.then(data => console.log(data));
```

### Response Format

#### Success Response (200)
```json
{
  "status": "success",
  "message": "CSV file uploaded successfully.",
  "data": {
    "file_id": 123,
    "filename": "import_2024-01-01_12-00-00_abc123.csv",
    "original_name": "my_data.csv",
    "file_path": "public://imports/import_2024-01-01_12-00-00_abc123.csv",
    "file_url": "http://your-site.com/sites/default/files/imports/import_2024-01-01_12-00-00_abc123.csv",
    "file_size": 1024,
    "upload_time": "2024-01-01T12:00:00+00:00",
    "uploaded_by": "username"
  }
}
```

#### Error Response (400/500)
```json
{
  "error": "Error message",
  "status": "error",
  "details": "Additional error details (if available)"
}
```

## File Storage

- Files are stored in `public://imports/` directory
- Filenames are automatically generated with timestamp and unique ID
- Files are created as permanent Drupal file entities
- Original filename is preserved in the response

## Security

- Requires valid user authentication via basic_auth
- Users must have "Upload CSV files" permission
- Only CSV files are accepted (validated by extension and MIME type)
- All uploads are logged for audit purposes

## Permissions

- **Upload CSV files**: Allows users to upload CSV files via the API

## Troubleshooting

1. **403 Forbidden**: Check user permissions and basic auth credentials
2. **400 Bad Request**: Ensure you're uploading a valid CSV file
3. **500 Internal Server Error**: Check Drupal logs for detailed error information

## Logging

All upload attempts (successful and failed) are logged using Drupal's logging system under the 'dkan_importer_api' channel.
