# 📋 Dynamic HR Form Template System

A comprehensive Laravel-based HR management system that enables Admin/HR personnel to create dynamic form templates and employees to submit responses, with advanced Excel import/export capabilities.

## 🎯 Project Overview

This system provides a complete solution for managing HR forms dynamically without requiring code changes for each new form type. It features JWT-based authentication, role-based access control, and robust Excel integration for bulk operations.

**Key Features:**
- 🔐 JWT Authentication & Authorization
- 👥 Role-Based Access Control (Admin, HR, Employee)
- 📝 Dynamic Form Template Builder
- 📊 Form Submission Management
- 📤 Excel Import/Export with Validation
- 🔍 Advanced Filtering & Search
- 📖 Complete API Documentation (Swagger)

---

## 🛠️ Technical Stack & Implementation

### **Requirement 1: Authentication**
**Need:** Secure JWT-based authentication system with token management

**Solution Used:** `php-open-source-saver/jwt-auth` v2.1

**Implementation:**
- Custom `AuthService` handles all authentication logic
- JWT tokens with 60-minute TTL (configurable)
- Token refresh mechanism for seamless user experience
- Rate limiting on auth endpoints (5 requests/minute)
- Middleware: `JwtMiddleware` validates tokens on protected routes
- Custom exception handling via `AuthenticationException`
- Endpoints: `/api/auth/login`, `/api/auth/logout`, `/api/auth/refresh`, `/api/auth/me`

**Key Files:**
```
app/Services/AuthService.php
app/Http/Controllers/AuthenticationController.php
app/Http/Middleware/JwtMiddleware.php
app/Exceptions/AuthenticationException.php
```

---

### **Requirement 2: Authorization & Permissions**
**Need:** Role-based access control with granular permissions

**Solution Used:** `spatie/laravel-permission` v6.10

**Implementation:**
- Three roles: `admin`, `hr`, `employee`
- 27+ granular permissions for fine-grained access control
- Middleware: `CheckRole` and `CheckPermission` protect routes
- Database-backed role/permission assignment
- Seeder creates default roles, permissions, and test users
- Users can have multiple roles and direct permissions
- Example permissions: `view-users`, `create-forms`, `approve-submissions`

**Key Files:**
```
app/Http/Middleware/CheckRole.php
app/Http/Middleware/CheckPermission.php
database/seeders/RolePermissionSeeder.php
```

**Sample Usage:**
```php
// In routes
Route::middleware(['auth:api', 'role:admin,hr'])->group(function () {
    // Admin/HR only routes
});

// In controllers
if (!$user->hasPermissionTo('approve-submissions')) {
    return $this->forbiddenResponse();
}
```

---

### **Requirement 3: Dynamic Form Templates**
**Need:** Admin/HR can create customizable form templates with various field types

**Solution Used:** Custom implementation with Laravel Eloquent relationships

**Implementation:**
- `FormTemplate` model with soft deletes
- `FormField` model supporting 9 field types:
  - text, textarea, number, email, date, dropdown, checkbox, radio, file
- JSON fields for dynamic options and validation rules
- Field ordering system
- Template statuses: `active`, `inactive`, `draft`
- Template duplication feature for quick form creation
- Eager loading with `->with('fields')` for performance

**Database Structure:**
```sql
form_templates: id, title, description, status, created_by, timestamps, deleted_at
form_fields: id, form_template_id, field_type, label, placeholder, 
             options (JSON), validation_rules (JSON), is_required, order
```

**Key Files:**
```
app/Models/FormTemplate.php
app/Models/FormField.php
app/Http/Controllers/FormTemplateController.php
database/migrations/2025_12_23_140342_create_form_templates_table.php
database/migrations/2025_12_23_140353_create_form_fields_table.php
```

**Features:**
- Dynamic validation rules stored as JSON: `{"min": 18, "max": 100}`
- Template versioning through duplication
- Cascade deletion of fields when template is deleted

---

### **Requirement 4: Form Submissions**
**Need:** Employees submit forms, Admin/HR review and approve/reject

**Solution Used:** Custom implementation with Laravel Eloquent + Database Transactions

**Implementation:**
- `FormSubmission` model tracks submission metadata
- `SubmissionResponse` model stores individual field responses
- Submission statuses: `draft`, `submitted`, `approved`, `rejected`
- Draft submissions can be edited/deleted by owner
- Dynamic validation based on form field configuration
- Admin/HR can filter by template, user, status, date range
- Comment/feedback system for rejected submissions
- Statistics dashboard for submission analytics

**Database Structure:**
```sql
form_submissions: id, form_template_id, user_id, status, 
                  submitted_at, reviewed_at, reviewed_by, comments
submission_responses: id, form_submission_id, form_field_id, response_value
```

**Key Files:**
```
app/Models/FormSubmission.php
app/Models/SubmissionResponse.php
app/Http/Controllers/Employee/FormSubmissionController.php
app/Http/Controllers/Admin/FormSubmissionAdminController.php
app/Http/Requests/FormSubmissionRequest.php
```

**Validation Flow:**
1. `FormSubmissionRequest` dynamically generates rules based on form fields
2. Type validation: email format, number range, date format, dropdown options
3. Required field enforcement (only on submit, not draft)
4. Custom validation rules from field configuration (min/max, regex)

---

### **Requirement 5: Excel Import/Export**
**Need:** Bulk import submissions from Excel, export submissions with filtering

**Solution Used:** `maatwebsite/excel` v3.1.67 (Laravel Excel)

**Implementation:**

**Export Features:**
- Dynamic column generation based on form fields
- Professional styling with colors and formatting
- Auto-width columns for readability
- Filter by template, status, user, date range
- Memory optimization for large datasets (chunking)
- Includes employee details: name, email, department, position

**Import Features:**
- Download sample Excel template with proper headers
- Field validation during import (type, required, min/max, options)
- Row-by-row error reporting with line numbers
- Preview/validate endpoint before actual import
- **Background processing using Laravel queues (Redis)**
- **Chunking: Automatically splits large CSV files into 10,000-row chunks for parallel processing**
- **Parallel processing with multiple queue workers for faster imports**
- File storage in `storage/app/imports` with unique filenames
- Real-time import status tracking and retry functionality
- Batch processing with database transactions per chunk
- Import statistics: imported count, skipped count, errors list
- Data validation dropdowns in Excel template

**Key Files:**
```
app/Exports/FormSubmissionsExport.php
app/Exports/FormTemplateExport.php
app/Imports/FormSubmissionsImport.php
app/Imports/QueuedFormSubmissionsImport.php
app/Jobs/ProcessFormImport.php
app/Jobs/ProcessFormImportChunk.php
app/Http/Controllers/ExcelController.php
config/excel.php
config/queue.php
```

**Validation in Imports:**
```php
// Validates field type
- Email: filter_var($value, FILTER_VALIDATE_EMAIL)
- Number: is_numeric() + min/max from validation_rules
- Date: Carbon::parse() with exception handling
- Dropdown: in_array($value, $options)

// Validates custom rules
- Min/max for numbers and text length
- Regex patterns
- Required fields
```

**Endpoints:**
- `GET /api/admin/submissions/export` - Download Excel
- `GET /api/admin/form-templates/{id}/excel-template` - Sample template
- `POST /api/admin/submissions/import` - Upload and import (queues background job)
- `POST /api/admin/submissions/import/validate` - Preview validation
- `GET /api/admin/submissions/imports` - List user imports
- `GET /api/admin/submissions/import/status/{importId}` - Get import status
- `POST /api/admin/submissions/import/{importId}/retry` - Retry failed import

**Queue Processing:**
To process background import jobs, run queue workers. For parallel processing of chunks:

**Development (Local):**
1. Ensure Redis is running (`docker-compose up redis`).
2. Run multiple workers in separate terminals:
   ```bash
   # Terminal 1
   while true; do php -d memory_limit=1024M artisan queue:work --tries=3 --timeout=600 --once; done

   # Terminal 2
   while true; do php -d memory_limit=1024M artisan queue:work --tries=3 --timeout=600 --once; done

   # Repeat for 4+ workers
   ```

**Production (Server):**
Use Supervisor to manage queue workers persistently:

1. **Install Supervisor:**
   ```bash
   # Ubuntu/Debian
   sudo apt-get install supervisor

   # CentOS/RHEL
   sudo yum install supervisor
   ```

2. **Create Supervisor Configuration:**
   ```bash
   sudo nano /etc/supervisor/conf.d/dynamic_hr_queue.conf
   ```

   Add this content (for nginx server):
   ```ini
   [program:dynamic_hr_queue_worker]
   process_name=%(program_name)s_%(process_num)02d
   command=php /var/www/dynamic_hr/artisan queue:work --sleep=3 --tries=3 --timeout=600 --max-jobs=1000
   directory=/var/www/dynamic_hr
   autostart=true
   autorestart=true
   numprocs=4
   user=www-data
   redirect_stderr=true
   stdout_logfile=/var/www/dynamic_hr/storage/logs/queue.log
   ```

3. **Update Configuration:**
   - Adjust `numprocs=4` for number of worker processes (based on your server CPU cores)
   - User is set to `www-data` (nginx default)

4. **Start Supervisor:**
   ```bash
   sudo supervisorctl reread
   sudo supervisorctl update
   sudo supervisorctl start dynamic_hr_queue_worker:*
   ```

5. **Monitor Workers:**
   ```bash
   sudo supervisorctl status
   sudo supervisorctl tail -f dynamic_hr_queue_worker
   ```
---

### **Requirement 6: API Documentation**
**Need:** Interactive API documentation for all endpoints

**Solution Used:** `darkaonline/l5-swagger` (Swagger/OpenAPI)

**Implementation:**
- Complete OpenAPI 3.0 annotations in controllers
- Interactive Swagger UI at `/api/documentation`
- 44+ documented endpoints across all modules
- Request/response schemas with examples
- JWT Bearer authentication configuration
- Grouped by tags: Authentication, Users, Roles, Form Templates, Form Submissions, Excel
- Enum definitions for field types, statuses, roles

**Generate Docs:**
```bash
php artisan l5-swagger:generate
```

**Access:** `http://localhost:8000/api/documentation`

---

### **Requirement 7: Performance Monitoring & Debugging**
**Need:** Monitor application performance, database queries, requests, and memory usage

**Solution Used:** `laravel/telescope` v5.16

**Implementation:**
- Real-time monitoring dashboard at `/telescope`
- Tracks requests with execution time and memory usage
- Database query monitoring with slow query detection
- Exception tracking and debugging
- Job monitoring and queue inspection
- Cache hit/miss statistics
- Model events tracking
- Mail preview and debugging

**Key Features:**
- **Requests Watcher:** Monitor HTTP request/response cycles, execution time, memory consumption
- **Queries Watcher:** Track all database queries, execution time, and detect N+1 problems
- **Exceptions Watcher:** Capture all exceptions with full stack traces
- **Jobs Watcher:** Monitor queued jobs and their status
- **Performance Metrics:** Memory usage, CPU time, and execution duration

**Configuration:**
```php
// Only enabled in local environment by default
'enabled' => env('TELESCOPE_ENABLED', true),

// Admin-only access in production
Gate::define('viewTelescope', function ($user) {
    return $user->hasRole('admin');
});
```

**Access:** `http://localhost:8000/telescope`

**Authorization:** Only accessible by admin users in production

---

## 🗄️ Database Architecture

### **Performance Optimizations:**
- **Composite Indexes** for frequently queried column combinations:
  - `(status, created_at)` on templates and submissions
  - `(user_id, status)` for employee submission filters
  - `(form_template_id, status)` for template-specific queries
  - `(form_submission_id, form_field_id)` for response lookups

### **Relationships:**
```
User -> hasMany(FormSubmission)
User -> hasMany(FormTemplate) [created_by]
FormTemplate -> hasMany(FormField)
FormTemplate -> hasMany(FormSubmission)
FormSubmission -> hasMany(SubmissionResponse)
FormSubmission -> belongsTo(User)
FormSubmission -> belongsTo(FormTemplate)
SubmissionResponse -> belongsTo(FormSubmission)
SubmissionResponse -> belongsTo(FormField)
```

---

## 📦 Installation & Setup

### **Prerequisites:**
- PHP 8.1+
- Composer
- Docker & Docker Compose (for PostgreSQL and Redis)

### **Installation Steps:**

1. **Clone the repository**
```bash
git clone <repository-url>
cd dynamic_hr
```

2. **Start PostgreSQL and Redis with Docker**
```bash
docker-compose up -d
```

This will start containers:
- **PostgreSQL:**
  - Host: 127.0.0.1
  - Port: 5432 (mapped to 8002 on host if port conflict)
  - Database: dynamic_hr_db
  - Username: dynamic_hr_user
  - Password: password
- **Redis:**
  - Host: 127.0.0.1
  - Port: 6379

3. **Install PHP dependencies**
```bash
composer install
```

4. **Environment setup**
```bash
cp .env.example .env
php artisan key:generate
php artisan jwt:secret
```

4. **Environment setup**
```bash
cp .env.example .env
php artisan key:generate
php artisan jwt:secret
```

5. **Configure database** (`.env` is already configured for Docker)

The default `.env.example` is pre-configured for Docker setup:
```env
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=dynamic_hr_db
DB_USERNAME=dynamic_hr_user
DB_PASSWORD=password
```

6. **Run migrations and seeders**
```bash
php artisan migrate:fresh --seed
```

7. **Generate API documentation**
```bash
php artisan l5-swagger:generate
```

8. **Start the server**
```bash
php artisan serve
```

9. **Run queue workers for background imports** (in separate terminals)
```bash
# Terminal 1
while true; do php -d memory_limit=1024M artisan queue:work --tries=3 --timeout=600 --once; done

# Terminal 2 (optional, for parallel processing)
while true; do php -d memory_limit=1024M artisan queue:work --tries=3 --timeout=600 --once; done
```

10. **Access the application**
- API: `http://localhost:8000/api`
- Swagger Docs: `http://localhost:8000/api/documentation`
- Telescope (Performance Monitor): `http://localhost:8000/telescope`

---

## 👤 Default Test Users

| Role     | Email                    | Password    |
|----------|--------------------------|-------------|
| Admin    | admin@dynamichr.com      | Admin@123   |
| HR       | hr@dynamichr.com         | HR@123      |
| Employee | employee@dynamichr.com   | Employee@123|

---

## 🔐 Authentication Flow

1. **Register/Login:**
```bash
POST /api/auth/login
{
  "email": "admin@dynamichr.com",
  "password": "Admin@123"
}

Response: {
  "success": true,
  "message": "Login successful",
  "data": {
    "token": "eyJ0eXAiOiJKV1QiLCJ...",
    "token_type": "bearer",
    "expires_in": 3600
  }
}
```

2. **Use token in subsequent requests:**
```bash
Authorization: Bearer <your-token>
```

3. **Refresh token before expiry:**
```bash
POST /api/auth/refresh
```

---

## 📚 API Usage Examples

### **Create Form Template (Admin/HR)**
```bash
POST /api/admin/form-templates
Authorization: Bearer <token>
Content-Type: application/json

{
  "title": "Employee Onboarding",
  "description": "New employee information form",
  "status": "active",
  "fields": [
    {
      "field_type": "text",
      "label": "Full Name",
      "is_required": true,
      "order": 1
    },
    {
      "field_type": "email",
      "label": "Email Address",
      "is_required": true,
      "order": 2
    },
    {
      "field_type": "number",
      "label": "Age",
      "is_required": true,
      "validation_rules": {"min": 18, "max": 65},
      "order": 3
    },
    {
      "field_type": "dropdown",
      "label": "Department",
      "options": ["IT", "HR", "Finance", "Marketing"],
      "is_required": true,
      "order": 4
    }
  ]
}
```

### **Submit Form (Employee)**
```bash
POST /api/employee/submissions
Authorization: Bearer <token>
Content-Type: application/json

{
  "form_template_id": 1,
  "status": "submitted",
  "responses": {
    "1": "John Doe",
    "2": "john.doe@company.com",
    "3": "28",
    "4": "IT"
  }
}
```

### **Export Submissions (Admin/HR)**
```bash
GET /api/admin/submissions/export?form_template_id=1&status=submitted&date_from=2025-01-01
Authorization: Bearer <token>

# Downloads Excel file with all matching submissions
```

### **Import Submissions (Admin/HR)**
```bash
# 1. Download template
GET /api/admin/form-templates/1/excel-template

# 2. Fill Excel/CSV file with data (supports large files via chunking)

# 3. Upload and import (queues background job)
POST /api/admin/submissions/import
Authorization: Bearer <token>
Content-Type: multipart/form-data

form_template_id: 1
file: <csv-file>

# Response: {"success": true, "message": "Import started", "data": {"import_id": 123}}

# 4. Check import status
GET /api/admin/submissions/import/status/123
Authorization: Bearer <token>

# Response: {"success": true, "data": {"status": "processing", "imported_count": 5000, "skipped_count": 0}}

# 5. Retry if failed
POST /api/admin/submissions/import/123/retry
Authorization: Bearer <token>
```

---

## 🔒 Security Features

- ✅ JWT token authentication with expiration
- ✅ Role-based access control (RBAC)
- ✅ Permission-based authorization
- ✅ Rate limiting on authentication endpoints
- ✅ Password hashing with bcrypt
- ✅ SQL injection prevention (Eloquent ORM)
- ✅ XSS protection
- ✅ Input validation and sanitization
- ✅ Excel file type and size validation (10MB limit)
- ✅ Submission ownership verification
- ✅ Comprehensive error logging

---

## 📂 Project Structure

```
app/
├── Http/
│   ├── Controllers/
│   │   ├── Admin/
│   │   │   ├── FormSubmissionAdminController.php
│   │   │   ├── UserManagementController.php
│   │   │   └── EmployeeController.php
│   │   ├── Employee/
│   │   │   └── FormSubmissionController.php
│   │   ├── AuthenticationController.php
│   │   ├── FormTemplateController.php
│   │   ├── ExcelController.php
│   │   └── RoleController.php
│   ├── Middleware/
│   │   ├── JwtMiddleware.php
│   │   ├── CheckRole.php
│   │   └── CheckPermission.php
│   └── Requests/
│       ├── FormSubmissionRequest.php
│       └── UpdateFormSubmissionRequest.php
├── Models/
│   ├── User.php
│   ├── FormTemplate.php
│   ├── FormField.php
│   ├── FormSubmission.php
│   └── SubmissionResponse.php
├── Services/
│   └── AuthService.php
├── Exports/
│   ├── FormSubmissionsExport.php
│   └── FormTemplateExport.php
├── Imports/
│   └── FormSubmissionsImport.php
└── Traits/
    └── ApiResponse.php
```

---

## 🧪 Testing

```bash
# Run all tests
php artisan test

# Run specific test suite
php artisan test --testsuite=Feature

# Run with coverage
php artisan test --coverage
```

---

## 🐛 Troubleshooting

### **Issue: JWT Token Invalid**
```bash
# Regenerate JWT secret
php artisan jwt:secret

# Clear config cache
php artisan config:clear
```

### **Issue: Database Connection Failed**
- Verify PostgreSQL Docker container is running: `docker ps`
- Start the container if stopped: `docker-compose up -d`
- Check `.env` database credentials match Docker configuration
- Test connection: `docker exec -it <container_name> psql -U dynamic_hr_user -d dynamic_hr_db`

### **Issue: Excel Import Failing**
- Check file size (max 50MB)
- Verify file format (xlsx, xls, csv only)
- Ensure column headers match field labels (lowercase with underscores)
- Check logs: `storage/logs/laravel.log`

### **Issue: File Upload Fails with "The file failed to upload"**

This is typically caused by PHP server configuration limits. Check and update your `php.ini`:

```ini
# Recommended settings for production
upload_max_filesize = 50M
post_max_size = 50M
max_execution_time = 600
memory_limit = 512M
max_input_time = 600
```

**For Apache:**
```bash
sudo nano /etc/php/8.4/apache2/php.ini
# Edit the values above
sudo systemctl restart apache2
```

**For Nginx with PHP-FPM:**
```bash
sudo nano /etc/php/8.4/fpm/php.ini
# Edit the values above
sudo systemctl restart php8.4-fpm
```

**For Nginx, also update nginx.conf:**
```nginx
client_max_body_size 50M;
```

Then restart:
```bash
sudo systemctl restart nginx
```

**Verify current limits:**
```bash
php -i | grep -E "upload_max_filesize|post_max_size|max_execution_time"
```

**Error Messages:**
- "File size exceeds server's maximum upload size" → Increase `upload_max_filesize`
- "File size exceeds maximum allowed size" → Increase application limit (currently 50MB)
- "File was only partially uploaded" → Increase `max_input_time` or check network
- "Failed to write file to disk" → Check disk space and permissions on `/tmp`

### **Server-Specific Setup (Nginx at /var/www/dynamic_hr):**

**Install Redis (Required for Queue Processing):**
The application uses Redis for background job queuing.

**Run Redis with Docker:**
```bash
# Run only Redis from docker-compose
docker-compose up -d redis

# Or run Redis in a separate container
docker run -d --name dynamic_hr_redis -p 6379:6379 redis:7-alpine

# Verify Redis is running
docker exec dynamic_hr_redis redis-cli ping  # Should respond with "PONG"
```

**Permissions:**
Ensure `www-data` user owns the project and has write access to storage:
```bash
sudo chown -R www-data:www-data /var/www/dynamic_hr
sudo chmod -R 755 /var/www/dynamic_hr
sudo chmod -R 775 /var/www/dynamic_hr/storage
sudo chmod -R 775 /var/www/dynamic_hr/bootstrap/cache
```

**Nginx Configuration:**
Make sure your nginx site config points to `/var/www/dynamic_hr/public`:
```nginx
server {
    listen 80;
    server_name your-domain.com;
    root /var/www/dynamic_hr/public;
    
    # ... rest of config
}
```

**PHP Configuration:**
Update `/etc/php/8.4/fpm/php.ini` for large file uploads:
```ini
upload_max_filesize = 50M
post_max_size = 50M
memory_limit = 1024M
max_execution_time = 600
```

Restart PHP-FPM:
```bash
sudo systemctl restart php8.4-fpm
```

---

## 📖 Additional Resources

- **Swagger Documentation:** `/api/documentation`
- **Telescope Performance Monitor:** `/telescope`
- **Laravel Documentation:** https://laravel.com/docs
- **JWT Auth:** https://github.com/PHP-Open-Source-Saver/jwt-auth
- **Spatie Permissions:** https://spatie.be/docs/laravel-permission
- **Laravel Excel:** https://docs.laravel-excel.com
- **Laravel Telescope:** https://laravel.com/docs/10.x/telescope

---

## 📝 License

This project is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).

---

## 👨‍💻 Developer

**Project:** Dynamic HR Form Template System  
**Framework:** Laravel 10+  
**Database:** PostgreSQL  
**API Documentation:** Swagger/OpenAPI 3.0  
**Version:** 1.0.0  
**Date:** December 2025

## Contributing

Thank you for considering contributing to the Laravel framework! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
