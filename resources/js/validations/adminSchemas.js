import * as Yup from 'yup';

export const adminProductSchema = Yup.object().shape({
    name: Yup.string()
        .min(3, 'Product title must be at least 3 characters')
        .max(255, 'Product title cannot exceed 255 characters')
        .required('Product title is required'),
    category_id: Yup.mixed().required('Category selection is required'),
    brand_id: Yup.mixed().nullable(),
    price: Yup.number()
        .typeError('Price must be a valid number')
        .positive('Price must be greater than zero')
        .required('Price is required'),
    discount_price: Yup.number()
        .typeError('Discount price must be a valid number')
        .positive('Discount price must be positive')
        .nullable()
        .transform((curr, orig) => (orig === '' ? null : curr)),
    stock_quantity: Yup.number()
        .typeError('Stock quantity must be a valid number')
        .integer('Stock quantity must be a whole number')
        .min(0, 'Stock quantity cannot be negative')
        .required('Stock quantity is required'),
    short_description: Yup.string()
        .max(1000, 'Description cannot exceed 1000 characters')
        .nullable(),
    description: Yup.string().nullable(),
    image_path: Yup.string().nullable(),
    is_featured: Yup.boolean().default(false),
    is_active: Yup.boolean().default(true),
});

export const adminCreateProductSchema = adminProductSchema;
export const adminEditProductSchema = adminProductSchema;

export const adminOrderSearchSchema = Yup.object().shape({
    search: Yup.string().max(100, 'Search query too long'),
    status: Yup.string().default('all'),
});

export const adminCategorySchema = Yup.object().shape({
    name: Yup.string()
        .min(2, 'Category name must be at least 2 characters')
        .max(100, 'Category name cannot exceed 100 characters')
        .required('Category name is required'),
    slug: Yup.string().max(120, 'Slug cannot exceed 120 characters').nullable(),
    parent_id: Yup.mixed().nullable(),
    icon: Yup.string().max(50).nullable(),
    badge: Yup.string().max(20).nullable(),
    is_offer: Yup.boolean().default(false),
    is_active: Yup.boolean().default(true),
});

export const adminBannerSchema = Yup.object().shape({
    title: Yup.string()
        .min(2, 'Title must be at least 2 characters')
        .max(150, 'Title cannot exceed 150 characters')
        .required('Banner title is required'),
    subtitle: Yup.string().max(255).nullable(),
    badge: Yup.string().max(50).nullable(),
    image_path: Yup.string().required('Banner image is required'),
    link_url: Yup.string().max(255).nullable(),
    button_text: Yup.string().max(50).default('Shop Now'),
    position: Yup.string()
        .oneOf(
            ['hero', 'promo_top', 'promo_side', 'popup'],
            'Invalid banner position',
        )
        .required('Position placement is required'),
    sort_order: Yup.number().integer().min(1).default(1),
    is_active: Yup.boolean().default(true),
});

export const adminCouponSchema = Yup.object().shape({
    code: Yup.string()
        .min(3, 'Code must be at least 3 characters')
        .max(50, 'Code cannot exceed 50 characters')
        .required('Coupon code is required'),
    description: Yup.string().max(255).nullable(),
    discount_type: Yup.string()
        .oneOf(['percent', 'fixed'], 'Invalid discount type')
        .required('Discount type is required'),
    discount_value: Yup.number()
        .positive('Discount must be greater than zero')
        .required('Discount value is required'),
    min_spend: Yup.number().min(0).nullable(),
    max_discount: Yup.number().min(0).nullable(),
    usage_limit: Yup.number().integer().min(1).nullable(),
    is_active: Yup.boolean().default(true),
});

export const adminStoreSchema = Yup.object().shape({
    name: Yup.string()
        .min(3, 'Branch name must be at least 3 characters')
        .max(150, 'Branch name cannot exceed 150 characters')
        .required('Branch name is required'),
    branch_type: Yup.string().required('Branch type is required'),
    city: Yup.string().required('City is required'),
    address: Yup.string().required('Full address is required'),
    phone: Yup.string().required('Contact phone is required'),
    email: Yup.string().email('Invalid email').nullable(),
    opening_hours: Yup.string().required('Opening hours is required'),
    is_active: Yup.boolean().default(true),
});

export const adminSettingsSchema = Yup.object().shape({
    site_name: Yup.string().default('Robins Computer'),
    site_tagline: Yup.string().nullable(),
    site_address: Yup.string().nullable(),
    site_hotline: Yup.string().required('Hotline phone is required'),
    support_email: Yup.string()
        .email('Invalid email')
        .required('Support email is required'),
    announcement_text: Yup.string().required('Announcement text is required'),
    announcement_active: Yup.boolean().default(true),
    shipping_inside_dhaka: Yup.number()
        .min(0)
        .required('Shipping rate is required'),
    shipping_outside_dhaka: Yup.number()
        .min(0)
        .required('Shipping rate is required'),
    free_shipping_threshold: Yup.number().min(0).nullable(),
    mail_mailer: Yup.string().default('smtp'),
    mail_host: Yup.string().nullable(),
    mail_port: Yup.number().nullable(),
    mail_username: Yup.string().nullable(),
    mail_password: Yup.string().nullable(),
    mail_encryption: Yup.string().nullable(),
    mail_from_address: Yup.string().email('Invalid email').nullable(),
    mail_from_name: Yup.string().nullable(),
});

export const adminBlogSchema = Yup.object().shape({
    title: Yup.string()
        .min(5, 'Title must be at least 5 characters')
        .max(200, 'Title cannot exceed 200 characters')
        .required('Article title is required'),
    category: Yup.string().required('Category selection is required'),
    excerpt: Yup.string()
        .min(10, 'Excerpt must be at least 10 characters')
        .max(300, 'Excerpt cannot exceed 300 characters')
        .required('Short excerpt summary is required'),
    content: Yup.string()
        .min(20, 'Content must be at least 20 characters')
        .required('Full article body is required'),
    image_path: Yup.string().required('Banner thumbnail image is required'),
    author_name: Yup.string().required('Author name is required'),
    author_role: Yup.string().nullable(),
    read_time: Yup.string().required(
        'Reading time is required (e.g. 5 min read)',
    ),
    is_published: Yup.boolean().default(true),
});
