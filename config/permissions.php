<?php

// Module-by-action permission matrix (PRD section 15).
$modules = [
    'dashboard' => 'Dashboard',
    'products' => 'Products',
    'categories' => 'Categories',
    'orders' => 'Orders',
    'refunds' => 'Refunds',
    'customers' => 'Customers',
    'reviews' => 'Reviews',
    'selling_pages' => 'Selling pages',
    'content' => 'Content',
    'blog' => 'Blog',
    'media' => 'Media',
    'coupons' => 'Coupons',
    'chat' => 'Live chat',
    'seo' => 'SEO',
    'integrations' => 'Integrations',
    'theme' => 'Theme',
    'settings' => 'Settings',
    'staff' => 'Staff and roles',
    'activity' => 'Activity log',
];

$actions = ['view', 'create', 'edit', 'delete', 'publish'];

$all = fn (array $mods) => array_fill_keys($mods, $actions);

return [
    'modules' => $modules,
    'actions' => $actions,

    /*
     * Default roles. "super" bypasses every check. Admin has every permission,
     * but cannot manage Super Admins or change payment keys (those two are
     * reserved for Super Admins in code, not in the matrix).
     */
    'default_roles' => [
        'super-admin' => [
            'name' => 'Super Admin',
            'description' => 'Everything, including roles, staff accounts, payment keys and deleting data.',
            'is_super' => true,
            'permissions' => [],
        ],
        'admin' => [
            'name' => 'Admin',
            'description' => 'Everything except managing Super Admins and changing payment keys.',
            'is_super' => false,
            'permissions' => $all(array_keys($modules)),
        ],
        'moderator' => [
            'name' => 'Moderator',
            'description' => 'Reviews, live chat and customer messages, content pages and blog posts.',
            'is_super' => false,
            'permissions' => [
                'dashboard' => ['view'],
                'reviews' => $actions,
                'chat' => $actions,
                'content' => $actions,
                'blog' => $actions,
                'media' => ['view', 'create', 'edit'],
            ],
        ],
        'staff' => [
            'name' => 'Staff',
            'description' => 'Products, orders and selling pages.',
            'is_super' => false,
            'permissions' => [
                'dashboard' => ['view'],
                'products' => $actions,
                'categories' => $actions,
                'orders' => ['view', 'edit'],
                'customers' => ['view'],
                'selling_pages' => $actions,
                'media' => ['view', 'create', 'edit'],
            ],
        ],
    ],
];
