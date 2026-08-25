import React from 'react';
import { Link } from '@inertiajs/react';
import { Heart } from 'lucide-react';
import AccountLayout from './AccountLayout';
import { ProductImage } from '@/Components/ProductImage';
import { formatBdt } from '@/utils/formatters';
import { ROUTES } from '@/constants/endpoints';

export default function Wishlist({
    user,
    navCounts,
    techPoints,
    wishlistItems = [],
}) {
    return (
        <AccountLayout
            title="My Wishlist"
            active="wishlist"
            user={user}
            navCounts={navCounts}
            techPoints={techPoints}
        >
            <div>
                <div className="dash-tab-header">
                    <div>
                        <h2>Saved Wishlist</h2>
                        <p>
                            Keep track of products you plan to purchase or
                            include in your custom PC build.
                        </p>
                    </div>
                </div>

                {wishlistItems.length === 0 ? (
                    <div className="dash-empty-box">
                        <Heart size={44} className="dash-empty-icon" />
                        <h4 className="dash-empty-text">
                            Your wishlist is currently empty
                        </h4>
                        <p className="dash-empty-text">
                            Browse our catalog and click the heart icon on any
                            product to save it here.
                        </p>
                        <Link
                            href={ROUTES.SHOP}
                            className="btn btn-primary btn-sm mt-3"
                        >
                            Explore Products
                        </Link>
                    </div>
                ) : (
                    <div className="standard-products-grid">
                        {wishlistItems.map((item) => (
                            <div
                                key={item.id}
                                className="standard-product-card"
                            >
                                <Link
                                    href={`/products/${item.product?.slug}`}
                                    className="card-image-wrapper"
                                >
                                    <ProductImage
                                        product={item.product}
                                        alt={item.product?.name}
                                    />
                                </Link>
                                <div className="card-content-body">
                                    <span className="card-brand-tag">
                                        {item.product?.brand?.name}
                                    </span>
                                    <Link
                                        href={`/products/${item.product?.slug}`}
                                        className="card-product-title truncate-2"
                                    >
                                        {item.product?.name}
                                    </Link>
                                    <div className="card-pricing-row">
                                        <span className="current-price-tag">
                                            {formatBdt(item.product?.price)}
                                        </span>
                                    </div>
                                    <Link
                                        href={`/products/${item.product?.slug}`}
                                        className="btn btn-primary btn-sm w-100 mt-2"
                                    >
                                        View Product
                                    </Link>
                                </div>
                            </div>
                        ))}
                    </div>
                )}
            </div>
        </AccountLayout>
    );
}
