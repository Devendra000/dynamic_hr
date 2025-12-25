<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Routing\Controller as BaseController;

/**
 * @OA\Info(
 *     title="Dynamic HR Management System API",
 *     version="1.0.0",
 *     description="Enterprise-grade HR Management System with Role-Based Access Control",
 *     @OA\Contact(
 *         email="admin@dynamichr.com",
 *         name="API Support"
 *     )
 * )
 *
 * @OA\Server(
 *     url="https://hr.devendrahamal.com.np/api",
 *     description="Production Server"
 * )
 *
 * @OA\SecurityScheme(
 *     securityScheme="bearerAuth",
 *     type="http",
 *     scheme="bearer",
 *     bearerFormat="JWT",
 *     description="Enter your JWT token in the format: Bearer {token}"
 * )
 *
 * @OA\Tag(
 *     name="Authentication",
 *     description="User authentication and JWT token management"
 * )
 *
 * @OA\Tag(
 *     name="User Management",
 *     description="User CRUD operations and role/permission assignment"
 * )
 *
 * @OA\Tag(
 *     name="Role Management",
 *     description="Role CRUD operations and permission management"
 * )
 *
 * @OA\Tag(
 *     name="Permission Management",
 *     description="Permission CRUD operations"
 * )
 *
 * @OA\Tag(
 *     name="Form Template Management",
 *     description="Dynamic form template creation and field management"
 * )
 *
 * @OA\Tag(
 *     name="Form Submissions",
 *     description="Employee form submissions and admin review workflows"
 * )
 *
 * @OA\Tag(
 *     name="Excel Import/Export",
 *     description="Excel import/export for bulk submission management"
 * )
 */
class Controller extends BaseController
{
    use AuthorizesRequests, ValidatesRequests;
}
