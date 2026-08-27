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
    site_legal_name: Yup.string().nullable(),
    /*
     * hotline_number, not site_hotline. The form was renamed to the key the
     * storefront actually reads and this was left behind, so every save failed
     * validation on a field that is no longer rendered — Formik never called
     * onSubmit, and with no field to attach the error to, the Save button
     * simply did nothing.
     */
    hotline_number: Yup.string().required('Hotline phone is required'),
    hotline_hours: Yup.string().nullable(),
    support_email: Yup.string()
        .email('Invalid email')
        .required('Support email is required'),
    sales_email: Yup.string().email('Invalid email').nullable(),
    service_center_address: Yup.string().nullable(),
    footer_note: Yup.string()
        .max(120, 'Keep the note under 120 characters')
        .nullable(),
    announcement_text: Yup.string().required('Announcement text is required'),
    announcement_active: Yup.boolean().default(true),
    announcement_badge: Yup.string()
        .max(24, 'Keep the badge short — it is a pill, not a sentence')
        .nullable(),
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

/**
 * Recording a delivery.
 *
 * The lines are what matter: a receipt with a supplier and no products moves
 * no stock, so at least one line has to name a product and a quantity.
 */
export const adminStockReceiptSchema = Yup.object().shape({
    supplier_id: Yup.mixed().nullable(),
    invoice_number: Yup.string()
        .max(100, 'Invoice number is too long')
        .nullable(),
    received_on: Yup.string().required('Say when this delivery arrived'),
    note: Yup.string()
        .max(1000, 'Note cannot exceed 1000 characters')
        .nullable(),
    lines: Yup.array()
        .of(
            Yup.object().shape({
                unit: Yup.string(),
                quantity: Yup.number()
                    .typeError('Quantity must be a number')
                    .integer('Quantity must be a whole number')
                    .min(1, 'Quantity must be at least 1')
                    .max(100000, 'That is more than this form will accept')
                    .nullable()
                    .transform((curr, orig) => (orig === '' ? null : curr)),
                unit_cost: Yup.number()
                    .typeError('Unit cost must be a number')
                    .min(0, 'Unit cost cannot be negative')
                    .nullable()
                    .transform((curr, orig) => (orig === '' ? null : curr)),
            }),
        )
        .test(
            'has-a-real-line',
            'Add at least one product with a quantity.',
            (lines) =>
                (lines || []).some((l) => l?.unit && Number(l?.quantity) > 0),
        ),
});

/**
 * A counted correction.
 *
 * Deliberately a change rather than a new total — typing an absolute quantity
 * is how already-sold units used to come back to life — so zero is not a valid
 * adjustment, and "Other" has to explain itself.
 */
export const adminStockAdjustmentSchema = Yup.object().shape({
    quantity: Yup.number()
        .typeError('Enter how many units to add or remove')
        .integer('Enter a whole number of units')
        .notOneOf([0], 'Enter how many units to add or remove')
        .required('Enter how many units to add or remove'),
    reason: Yup.string().required('Choose a reason for this adjustment'),
    note: Yup.string()
        .max(1000, 'Note cannot exceed 1000 characters')
        .when('reason', {
            is: 'other',
            then: (schema) =>
                schema.required(
                    'Explain the adjustment when the reason is "Other"',
                ),
            otherwise: (schema) => schema.nullable(),
        }),
});

/** A supplier added without leaving a half-entered delivery. */
export const adminSupplierSchema = Yup.object().shape({
    name: Yup.string()
        .min(2, 'Supplier name must be at least 2 characters')
        .max(255, 'Supplier name cannot exceed 255 characters')
        .required('Give the supplier a name'),
    contact_name: Yup.string().max(255).nullable(),
    phone: Yup.string().max(40, 'Phone number is too long').nullable(),
    email: Yup.string().email('Enter a valid email address').nullable(),
});

/**
 * Taking back a delivered order.
 *
 * Condition per line decides where the units go, so a return that says nothing
 * came back is not a return.
 */
export const adminOrderReturnSchema = Yup.object().shape({
    note: Yup.string()
        .max(1000, 'Note cannot exceed 1000 characters')
        .nullable(),
    lines: Yup.array().test(
        'something-came-back',
        'Enter how many units came back.',
        (lines) =>
            Object.values(lines || {}).some(
                (l) => Number(l?.resellable) > 0 || Number(l?.damaged) > 0,
            ),
    ),
});

/**
 * Recording a running cost.
 *
 * Mirrors App\Http\Requests\Admin\ExpenseRequest — a bill that has not
 * happened yet does not belong in a period's accounts, so future dates are
 * refused here too rather than only at the server.
 */
export const adminExpenseSchema = Yup.object().shape({
    expense_category_id: Yup.string().required(
        'Choose what the money went on.',
    ),
    amount: Yup.number()
        .typeError('Enter an amount.')
        .moreThan(0, 'Enter what this cost.')
        .required('Enter what this cost.'),
    description: Yup.string()
        .trim()
        .max(255, 'Keep the description under 255 characters.')
        .required('Say what the money went on.'),
    incurred_on: Yup.date()
        .max(new Date(), 'An expense cannot be dated in the future.')
        .required('When was this incurred?'),
    reference: Yup.string().trim().max(100).nullable(),
    note: Yup.string().trim().max(1000).nullable(),
    supplier_id: Yup.mixed().nullable(),
});

/** Naming a spending category. */
export const adminExpenseCategorySchema = Yup.object().shape({
    name: Yup.string()
        .trim()
        .max(80, 'Keep the name under 80 characters.')
        .required('Give the category a name.'),
    note: Yup.string().trim().max(255).nullable(),
});

/** Handing a parcel to a carrier. */
export const adminDispatchSchema = Yup.object().shape({
    courier_id: Yup.string().required('Choose who is carrying this parcel.'),
    // Optional: a shop's own rider issues no consignment number.
    tracking_number: Yup.string().trim().max(100).nullable(),
});

/** A delivery company. */
export const adminCourierSchema = Yup.object().shape({
    name: Yup.string()
        .trim()
        .max(100, 'Keep the name under 100 characters.')
        .required('Give the courier a name.'),
    tracking_url_template: Yup.string()
        .trim()
        .max(500)
        .nullable()
        .test(
            'is-url',
            'The tracking link must start with http:// or https://.',
            (v) => !v || /^https?:\/\//i.test(v),
        )
        .test(
            'has-placeholder',
            'Put {tracking} where the consignment number goes, or leave this blank.',
            (v) => !v || v.includes('{tracking}'),
        ),
    phone: Yup.string().trim().max(40).nullable(),
    note: Yup.string().trim().max(255).nullable(),
});

/**
 * Recording a refund.
 *
 * Zero is allowed: a cash-on-delivery parcel that came back before the rider
 * collected anything is refunded on paper only, and the order still has to say
 * the customer owes nothing.
 */
export const adminRefundSchema = Yup.object().shape({
    amount: Yup.number()
        .typeError('Enter an amount.')
        .min(0, 'An amount cannot be negative.')
        .required('Enter how much was given back.'),
    method: Yup.string().required('Choose how the money went back.'),
    reason: Yup.string().required('Choose why this was refunded.'),
    reference: Yup.string().trim().max(120).nullable(),
    note: Yup.string().trim().max(1000).nullable(),
    refunded_on: Yup.date()
        .max(new Date(), 'A refund cannot be dated in the future.')
        .required('When did the money go back?'),
});

/**
 * A staff account.
 *
 * The password is required when creating and optional when editing — a blank
 * box on an existing account means "leave it as it is".
 */
export const adminStaffSchema = (isEditing = false) =>
    Yup.object().shape({
        name: Yup.string().trim().max(255).required('Enter their name.'),
        email: Yup.string()
            .trim()
            .email('Enter a valid email address.')
            .required('Enter their email — it is what they sign in with.'),
        phone: Yup.string()
            .trim()
            .nullable()
            .test(
                'bd-phone',
                'Enter a valid 11-digit Bangladeshi mobile number.',
                // Punctuation stripped first, and a dropped leading zero
                // allowed, matching the storefront's schemas and what the
                // server accepts. This was the one place still refusing
                // "+880 1712-345678" — which is how the app writes it.
                (v) =>
                    !v ||
                    /^(?:\+8801|8801|01|1)[3-9]\d{8}$/.test(
                        v.trim().replace(/[\s-]/g, ''),
                    ),
            ),
        role: Yup.string().required('Choose what their job covers.'),
        store_id: Yup.mixed().nullable(),
        password: isEditing
            ? Yup.string().nullable().min(8, 'Use at least 8 characters.')
            : Yup.string()
                  .min(8, 'Use at least 8 characters.')
                  .required('Set a password for them.'),
        password_confirmation: Yup.string().oneOf(
            [Yup.ref('password')],
            'The passwords do not match.',
        ),
    });

/**
 * A page the shop writes itself.
 *
 * A system page keeps its address — the footer links to /privacy and /terms by
 * name — so the slug field is disabled for those and the server ignores it.
 */
export const adminPageSchema = Yup.object().shape({
    slug: Yup.string()
        .trim()
        .required('Give the page an address')
        .matches(
            /^[a-z0-9-]+$/,
            'Lowercase letters, numbers and hyphens only — "return-policy", not "Return Policy"',
        )
        .max(64),
    title: Yup.string().trim().required('Give the page a title').max(160),
    subtitle: Yup.string().nullable().max(200),
    body: Yup.string()
        .trim()
        .required('A page with nothing on it is not worth publishing')
        .max(120000),
    meta_title: Yup.string().nullable().max(160),
    meta_description: Yup.string().nullable().max(500),
    is_published: Yup.boolean(),
});
