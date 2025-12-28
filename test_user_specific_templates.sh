# Test cURL Commands for User-Specific Templates (KYE/KYA)

# 1. Login as Admin to get JWT token
curl -X POST http://localhost:8000/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{
    "email": "admin@dynamichr.com",
    "password": "Admin@123"
  }'

# 2. Create a main template (base template for KYE/KYA)
curl -X POST http://localhost:8000/api/admin/form-templates \
  -H "Authorization: Bearer YOUR_JWT_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "title": "Employee Assessment Template",
    "description": "Base template for employee assessments",
    "status": "active",
    "fields": [
      {
        "field_type": "text",
        "label": "Full Name",
        "is_required": true,
        "order": 1
      },
      {
        "field_type": "textarea",
        "label": "Self Assessment",
        "is_required": true,
        "order": 2
      },
      {
        "field_type": "dropdown",
        "label": "Performance Rating",
        "options": ["Excellent", "Good", "Needs Improvement"],
        "is_required": true,
        "order": 3
      },
      {
        "field_type": "textarea",
        "label": "Goals for Next Year",
        "is_required": false,
        "order": 4
      }
    ]
  }'

# 3. Create KYE template for Employee (user_id: 3)
curl -X POST http://localhost:8000/api/admin/form-templates/1/assign-user \
  -H "Authorization: Bearer YOUR_JWT_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "user_id": 3,
    "template_type": "kye",
    "title": "Know Your Employee - Self Assessment",
    "description": "Personal development and performance review"
  }'

# 4. Login as Employee to get employee JWT token
curl -X POST http://localhost:8000/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{
    "email": "employee@dynamichr.com",
    "password": "Employee@123"
  }'

# 5. Employee views available forms (should see the KYE template)
curl -X GET http://localhost:8000/api/employee/forms \
  -H "Authorization: Bearer EMPLOYEE_JWT_TOKEN"

# 6. Employee submits the KYE form
curl -X POST http://localhost:8000/api/employee/submissions \
  -H "Authorization: Bearer EMPLOYEE_JWT_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "form_template_id": 2,
    "status": "submitted",
    "responses": {
      "1": "John Employee",
      "2": "I have been working hard on my projects and have improved my skills in Laravel and React. I believe I have met most of my quarterly goals.",
      "3": "Excellent",
      "4": "Next year, I want to focus on leadership skills and take on more mentoring responsibilities."
    }
  }'

# 7. Create KYA template for HR evaluation (user_id: 3, evaluated by HR)
curl -X POST http://localhost:8000/api/admin/form-templates/1/assign-user \
  -H "Authorization: Bearer YOUR_JWT_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "user_id": 3,
    "template_type": "kya",
    "title": "Know Your Associate - HR Evaluation",
    "description": "Managerial evaluation of employee performance"
  }'

# 8. HR/Admin submits the KYA evaluation form
curl -X POST http://localhost:8000/api/employee/submissions \
  -H "Authorization: Bearer YOUR_JWT_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "form_template_id": 3,
    "status": "submitted",
    "responses": {
      "5": "John Employee",
      "6": "John has shown excellent growth this year. He has taken initiative on several projects and his technical skills have improved significantly. He is a valuable team member.",
      "7": "Excellent",
      "8": "John should be considered for a leadership role next year. He has the technical skills and work ethic to succeed in a senior position."
    }
  }'

# 9. Admin/HR views all submissions (including both KYE and KYA)
curl -X GET http://localhost:8000/api/admin/submissions \
  -H "Authorization: Bearer YOUR_JWT_TOKEN"

# 10. Employee views their own submissions (only KYE, not KYA)
curl -X GET http://localhost:8000/api/employee/submissions \
  -H "Authorization: Bearer EMPLOYEE_JWT_TOKEN"
