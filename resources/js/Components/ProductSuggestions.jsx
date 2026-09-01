import React from 'react';
import { Link } from '@inertiajs/react';
import ProductImage from './ProductImage';
import { formatBdt } from '../utils/formatters';
import './ProductSuggestions.css';

/**
 * A row of other products worth looking at.
 *
 * Shared by the product page and the cart, because the two want the same
 * thing shown the same way — and because the product page's version was the
 * only one, so anywhere else that wanted suggestions would have copied it.
 *
 * Renders nothing when there is nothing to suggest. A heading over an empty
 * row is worse than no heading: it reads as something that failed to load.
 */
export default function ProductSuggestions({
    products = [],
    title = 'Similar Products',
    className = '',
}) {
    if (!products.length) return null;

    return (
        <section className={`pdp-related ${className}`.trim()}>
            <h3>{title}</h3>

            <div className="pdp-related-grid">
                {products.map((item) => (
                    <Link
                        key={item.id}
                        href={`/products/${item.slug}`}
                        className="pdp-related-item"
                    >
                        <ProductImage
                            product={item}
                            className="pdp-related-img"
                        />
                        <span className="pdp-related-name">{item.name}</span>
                        <span className="pdp-related-price">
                            {formatBdt(item.effective_price ?? item.price)}
                        </span>
                    </Link>
                ))}
            </div>
        </section>
    );
}
