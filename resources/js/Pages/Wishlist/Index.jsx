import React, { useState, useEffect } from 'react';
import { Head, Link } from '@inertiajs/react';
import MainLayout from '../../Layouts/MainLayout';
import {
    Button,
    EmptyState,
    Spinner,
    ProductImage,
    toast,
} from '../../Components';
import { wishlistService, cartService } from '../../services';
import { formatBdt } from '../../utils/formatters';
import { ROUTES } from '../../constants/endpoints';
import siteConfig from '../../constants/siteConfig';
import { Heart, ShoppingCart, Trash2 } from 'lucide-react';
import './Wishlist.css';

export default function Wishlist() {
    const [wishlist, setWishlist] = useState([]);
    const [loading, setLoading] = useState(true);
    const [actionId, setActionId] = useState(null);

    useEffect(() => {
        loadWishlist();
    }, []);

    const loadWishlist = async () => {
        setLoading(true);
        try {
            const data = await wishlistService.getWishlist();
            setWishlist(data || []);
        } catch (error) {
            console.error('Failed to load wishlist', error);
            toast.error('Failed to fetch wishlist items.', 'Error');
        } finally {
            setLoading(false);
        }
    };

    const handleRemove = async (productId) => {
        try {
            await wishlistService.removeFromWishlist(productId);
            setWishlist((prev) =>
                prev.filter(
                    (item) => (item.product?.id || item.id) !== productId,
                ),
            );
            toast.info('Item removed from wishlist.');
        } catch (error) {
            console.error('Failed to remove from wishlist', error);
            toast.error('Failed to remove item.', 'Error');
        }
    };

    const handleMoveToCart = async (product) => {
        setActionId(product.id);
        try {
            await cartService.addToCart(product.id, 1);
            await wishlistService.removeFromWishlist(product.id);
            setWishlist((prev) =>
                prev.filter(
                    (item) => (item.product?.id || item.id) !== product.id,
                ),
            );
            toast.success(
                `${product.name} moved to shopping cart!`,
                'Added to Cart',
            );
        } catch (error) {
            console.error('Failed to move to cart', error);
            toast.error('Failed to move item to cart.', 'Error');
        } finally {
            setActionId(null);
        }
    };

    return (
        <MainLayout>
            <Head title={`My Wishlist — ${siteConfig.name}`} />

            <div className="wishlist-page-wrapper container">
                <div className="wishlist-header-bar">
                    <div>
                        <h1 className="wishlist-title">My Wishlist</h1>
                        <span className="wishlist-count">
                            Saved hardware items ({wishlist.length})
                        </span>
                    </div>
                    {wishlist.length > 0 && (
                        <Link
                            href={ROUTES.SHOP}
                            className="btn btn-secondary btn-sm"
                        >
                            Continue Browsing
                        </Link>
                    )}
                </div>

                {loading ? (
                    <Spinner text="Loading saved items..." fullHeight />
                ) : wishlist.length === 0 ? (
                    <EmptyState
                        icon={Heart}
                        title="Your wishlist is empty"
                        description="You haven't saved any hardware products yet. Explore our latest tech deals and add items to your wishlist!"
                        actionLabel="Explore Hardware"
                        actionHref={ROUTES.SHOP}
                    />
                ) : (
                    <div className="wishlist-grid">
                        {wishlist.map((item) => {
                            const product = item.product || item;
                            const price =
                                product.discount_price || product.price;

                            return (
                                <div key={product.id} className="wishlist-card">
                                    <button
                                        type="button"
                                        onClick={() => handleRemove(product.id)}
                                        className="wishlist-remove-btn"
                                        aria-label="Remove from wishlist"
                                    >
                                        <Trash2 size={16} />
                                    </button>

                                    <Link
                                        href={ROUTES.PRODUCT_DETAIL(
                                            product.slug,
                                        )}
                                        className="wishlist-img-link"
                                    >
                                        <ProductImage
                                            product={product}
                                            alt={product.name}
                                            className="wishlist-img"
                                        />
                                    </Link>

                                    <div className="wishlist-info-box">
                                        <span className="wishlist-brand">
                                            {product.brand?.name ||
                                                'Authorized'}
                                        </span>
                                        <Link
                                            href={ROUTES.PRODUCT_DETAIL(
                                                product.slug,
                                            )}
                                            className="wishlist-product-name"
                                        >
                                            {product.name}
                                        </Link>

                                        <div className="wishlist-price">
                                            {formatBdt(price)}
                                        </div>
                                    </div>

                                    <Button
                                        variant="primary"
                                        size="md"
                                        fullWidth
                                        icon={ShoppingCart}
                                        loading={actionId === product.id}
                                        onClick={() =>
                                            handleMoveToCart(product)
                                        }
                                    >
                                        Move to Cart
                                    </Button>
                                </div>
                            );
                        })}
                    </div>
                )}
            </div>
        </MainLayout>
    );
}
