import React from 'react';
import { ProductCard } from './ProductCard';
import { useWishlist, useAddToCart } from '../hooks';
import './ProductSuggestions.css';

/**
 * A row of other products worth looking at.
 *
 * Draws the shop's own ProductCard rather than a tile of its own. The product
 * page used to render a cut-down version — image, name, price, nothing else —
 * so a suggestion could not be added to the cart, saved, compared or
 * quick-viewed, and it did not carry the discount or stock badges every other
 * card on the site does. A shopper had to open the product to do anything with
 * it, which is a step the suggestion existed to save.
 *
 * The card needs a wishlist and an add-to-cart to be a card, so this wires
 * both rather than making every caller pass them.
 *
 * Renders nothing when there is nothing to suggest: a heading over an empty
 * row reads as something that failed to load.
 */
export default function ProductSuggestions({
    products = [],
    title = 'Similar Products',
    className = '',
}) {
    const { wishlistIds, toggleWishlist } = useWishlist();
    const addToCart = useAddToCart();

    if (!products.length) return null;

    return (
        <section className={`product-suggestions ${className}`.trim()}>
            <h3 className="product-suggestions-title">{title}</h3>

            <div className="product-suggestions-grid">
                {products.map((product) => (
                    <ProductCard
                        key={product.id}
                        product={product}
                        variant="flash"
                        isWishlisted={wishlistIds.includes(product.id)}
                        onAddToCart={addToCart}
                        onToggleWishlist={() => toggleWishlist(product.id)}
                    />
                ))}
            </div>
        </section>
    );
}
