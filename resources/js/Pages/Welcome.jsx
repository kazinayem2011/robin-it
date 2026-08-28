import React, { useState, useEffect } from 'react';
import { Link } from '@inertiajs/react';
import { mainLayout } from '../Layouts/MainLayout';
import {
    productService,
    categoryService,
    bannerService,
    storeService,
    blogService,
} from '../services';
import {
    Zap,
    ArrowRight,
    ChevronLeft,
    ChevronRight,
    Flame,
    MapPin,
    Headset,
    Clock,
    Check,
    CreditCard,
    ShieldCheck,
    Truck,
    RefreshCw,
    Sparkles,
    Cpu,
    MonitorPlay,
    MemoryStick,
} from 'lucide-react';
import { BrandMarquee } from '../Components/BrandLogos';
import CountdownTimer from '../Components/CountdownTimer';
import EmptyState from '../Components/EmptyState';
import { ProductCard } from '../Components/ProductCard';
import SEOHead from '../Components/SEOHead';
import { ProductCardSkeleton } from '../Components/Skeleton';
import Tabs from '../Components/Tabs';
import { getCategoryIcon } from '../utils/iconMap';
import { formatBdt } from '../utils/formatters';
import siteConfig from '../constants/siteConfig';
import { ROUTES } from '../constants/endpoints';
import { useWishlist, useAddToCart } from '../hooks';
import './Welcome.css';

export default function Welcome({ banners = [], blogs = [] }) {
    const [bannersList, setBannersList] = useState(banners);
    const [blogsList, setBlogsList] = useState(blogs);
    const [flashSaleProducts, setFlashSaleProducts] = useState([]);
    const [bestSellersList, setBestSellersList] = useState([]);
    const [categoryBubbles, setCategoryBubbles] = useState([]);
    const [storesList, setStoresList] = useState([]);
    const [builderSpecs, setBuilderSpecs] = useState(null);
    const [loadingFlash, setLoadingFlash] = useState(true);
    const [loadingBestSellers, setLoadingBestSellers] = useState(true);
    const [activeHeroSlide, setActiveHeroSlide] = useState(0);
    const [activeTab, setActiveTab] = useState('all');
    const { wishlistIds, toggleWishlist } = useWishlist();
    const addToCart = useAddToCart();

    // Interactive PC Builder Mini Configurator State (100% Dynamic Keys)
    const [builderCpu, setBuilderCpu] = useState('');
    const [builderGpu, setBuilderGpu] = useState('');
    const [builderRam, setBuilderRam] = useState('');

    // Sync Banners if Inertia props update or on fresh mount
    useEffect(() => {
        if (banners && banners.length > 0) {
            setBannersList(banners);
        } else {
            bannerService
                .getBanners()
                .then((data) => {
                    if (data && Array.isArray(data)) {
                        setBannersList(data);
                    }
                })
                .catch(() => {});
        }
    }, [banners]);

    // Sync Blogs if Inertia props update or on fresh mount
    useEffect(() => {
        if (blogs && blogs.length > 0) {
            setBlogsList(blogs);
        } else {
            blogService
                .getBlogs({ limit: 3 })
                .then((data) => {
                    if (data && Array.isArray(data)) {
                        setBlogsList(data);
                    }
                })
                .catch(() => {});
        }
    }, [blogs]);

    // All active banner slides directly from Database (100% Admin Controlled)
    const activeHeroSlides = bannersList.filter((b) => b.is_active);

    // 1. Auto-advance hero slides dynamically based on available slide count
    useEffect(() => {
        if (activeHeroSlides.length <= 1) return;
        const slideTimer = setInterval(() => {
            setActiveHeroSlide((prev) => (prev + 1) % activeHeroSlides.length);
        }, 5000);
        return () => clearInterval(slideTimer);
    }, [activeHeroSlides.length]);

    // 3. Fetch dynamic Featured Categories (SSOT)
    useEffect(() => {
        categoryService
            .getFeaturedCategories()
            .then((data) => {
                if (data && Array.isArray(data)) {
                    setCategoryBubbles(data);
                }
            })
            .catch(() => {});
    }, []);

    // 4. Fetch dynamic Showrooms & Outlets (SSOT)
    useEffect(() => {
        storeService
            .getStores()
            .then((data) => {
                if (data && Array.isArray(data)) {
                    setStoresList(data);
                }
            })
            .catch(() => {});
    }, []);

    // 5. Fetch dynamic Flash Sale Products (SSOT)
    useEffect(() => {
        setLoadingFlash(true);
        productService
            .getFlashSale()
            .then((data) => {
                if (data && Array.isArray(data)) {
                    setFlashSaleProducts(data);
                }
            })
            .catch(() => {})
            .finally(() => setLoadingFlash(false));
    }, []);

    // 6. Fetch tabbed Best Sellers Products (SSOT)
    useEffect(() => {
        setLoadingBestSellers(true);
        productService
            .getFeatured(activeTab)
            .then((data) => {
                if (data && Array.isArray(data)) {
                    setBestSellersList(data);
                }
            })
            .catch(() => {})
            .finally(() => setLoadingBestSellers(false));
    }, [activeTab]);

    // 7. Fetch live PC builder hardware specs from Database (SSOT)
    useEffect(() => {
        productService
            .getBuilderQuickSpecs()
            .then((data) => {
                if (data) {
                    setBuilderSpecs(data);
                    const cpuKeys = Object.keys(data.cpu || {});
                    const gpuKeys = Object.keys(data.gpu || {});
                    const ramKeys = Object.keys(data.ram || {});
                    if (cpuKeys.length > 0) setBuilderCpu(cpuKeys[0]);
                    if (gpuKeys.length > 0) setBuilderGpu(gpuKeys[0]);
                    if (ramKeys.length > 0) setBuilderRam(ramKeys[0]);
                }
            })
            .catch(() => {});
    }, []);

    // Wishlist Toggle Helper

    return (
        <>
            <SEOHead
                title={`${siteConfig.name} — ${siteConfig.tagline}`}
                description="Bangladesh's Premier Tech Marketplace for Custom Gaming PC Rigs, Laptops, Graphics Cards, Processors, and Hardware Components with Genuine Brand Warranty."
            />

            <div className="homepage-master-container">
                {/* 1. HERO SLIDER & GRAPHICAL SHOWCASE */}
                <section className="hero-master-section">
                    <div className="container hero-banner-layout">
                        <div className="hero-slider-surface">
                            <div className="hero-slider-track">
                                {activeHeroSlides.map((slide, idx) => (
                                    <div
                                        key={slide.id || idx}
                                        className={`hero-slide-item ${activeHeroSlide === idx ? 'slide-active' : ''}`}
                                        style={{
                                            backgroundImage: `url(${slide.image_path || slide.image})`,
                                        }}
                                    >
                                        <div className="slide-gradient-overlay"></div>

                                        <div className="slide-content-box">
                                            {(slide.badge || slide.tag) && (
                                                <div className="slide-tag-badge">
                                                    <Zap size={13} />{' '}
                                                    {slide.badge || slide.tag}
                                                </div>
                                            )}

                                            {/*
                                             * Every slide is in the DOM at
                                             * once, so six banners put six
                                             * <h1>s in the document outline.
                                             * Assistive tech never heard them
                                             * — the inactive slides are
                                             * visibility:hidden — but a
                                             * crawler reading the markup did.
                                             * The slide on screen is the
                                             * page's heading; the rest are
                                             * text until their turn comes.
                                             */}
                                            {activeHeroSlide === idx ? (
                                                <h1 className="slide-title">
                                                    {slide.title}
                                                </h1>
                                            ) : (
                                                <p className="slide-title">
                                                    {slide.title}
                                                </p>
                                            )}
                                            {slide.subtitle && (
                                                <p className="slide-desc">
                                                    {slide.subtitle}
                                                </p>
                                            )}

                                            <div className="slide-action-btns">
                                                <Link
                                                    href={
                                                        slide.link_url ||
                                                        slide.primaryLink ||
                                                        ROUTES.SHOP
                                                    }
                                                    className="btn btn-primary btn-lg"
                                                >
                                                    <span>
                                                        {slide.button_text ||
                                                            slide.primaryCta ||
                                                            'Shop Now'}
                                                    </span>
                                                    <ArrowRight size={18} />
                                                </Link>
                                                {slide.secondaryLink && (
                                                    <Link
                                                        href={
                                                            slide.secondaryLink
                                                        }
                                                        className="btn btn-outline-white btn-lg"
                                                    >
                                                        <span>
                                                            {slide.secondaryCta ||
                                                                'Build Rig'}
                                                        </span>
                                                    </Link>
                                                )}
                                            </div>
                                        </div>
                                    </div>
                                ))}

                                {/* Slider Navigation Controls (Only shown if 2+ banners exist) */}
                                {activeHeroSlides.length > 1 && (
                                    <div className="slider-bottom-controls">
                                        <div className="slide-dots-indicator">
                                            {activeHeroSlides.map((_, i) => (
                                                <button
                                                    key={i}
                                                    onClick={() =>
                                                        setActiveHeroSlide(i)
                                                    }
                                                    className={`slider-dot ${activeHeroSlide === i ? 'dot-active' : ''}`}
                                                    aria-label={`Slide ${i + 1}`}
                                                >
                                                    <span className="dot-fill"></span>
                                                </button>
                                            ))}
                                        </div>

                                        <div className="slider-arrows-group">
                                            <button
                                                className="slider-arrow-btn"
                                                onClick={() =>
                                                    setActiveHeroSlide(
                                                        (prev) =>
                                                            prev === 0
                                                                ? activeHeroSlides.length -
                                                                  1
                                                                : prev - 1,
                                                    )
                                                }
                                                aria-label="Previous Slide"
                                            >
                                                <ChevronLeft size={20} />
                                            </button>
                                            <button
                                                className="slider-arrow-btn"
                                                onClick={() =>
                                                    setActiveHeroSlide(
                                                        (prev) =>
                                                            (prev + 1) %
                                                            activeHeroSlides.length,
                                                    )
                                                }
                                                aria-label="Next Slide"
                                            >
                                                <ChevronRight size={20} />
                                            </button>
                                        </div>
                                    </div>
                                )}
                            </div>
                        </div>
                    </div>
                </section>

                {/* 2. SERVICE TRUST & BENEFIT STRIP (DRY via siteConfig) */}
                <section className="container section-gap">
                    <div className="service-trust-grid">
                        <div className="trust-tile card-hover">
                            <div className="trust-icon-box">
                                <ShieldCheck size={28} />
                            </div>
                            <div className="trust-tile-text">
                                <strong>100% Genuine Products</strong>
                                <span>Direct official brand warranty</span>
                            </div>
                        </div>
                        <div className="trust-tile card-hover">
                            <div className="trust-icon-box">
                                <Truck size={28} />
                            </div>
                            <div className="trust-tile-text">
                                <strong>Express Nationwide Shipping</strong>
                                <span>64 Districts in 24 - 72 Hours</span>
                            </div>
                        </div>
                        <div className="trust-tile card-hover">
                            <div className="trust-icon-box">
                                <RefreshCw size={28} />
                            </div>
                            <div className="trust-tile-text">
                                <strong>7-Day Easy Replacement</strong>
                                <span>Hassle-free return policy</span>
                            </div>
                        </div>
                        <div className="trust-tile card-hover">
                            <div className="trust-icon-box">
                                <CreditCard size={28} />
                            </div>
                            <div className="trust-tile-text">
                                <strong>0% EMI Available</strong>
                                <span>Up to 36 Months on 28+ Banks</span>
                            </div>
                        </div>
                        <div className="trust-tile card-hover">
                            <div className="trust-icon-box">
                                <Headset size={28} />
                            </div>
                            <div className="trust-tile-text">
                                <strong>24/7 Expert Tech Support</strong>
                                <span>Direct live engineer assistance</span>
                            </div>
                        </div>
                    </div>
                </section>

                {/* 3. FEATURED CATEGORY BUBBLES AUTO-SLIDING INFINITE CAROUSEL */}
                <section className="container section-gap">
                    <div className="section-title-row">
                        <div className="title-text-group">
                            <span className="section-pill-tag">
                                EXPLORE HARDWARE
                            </span>
                            <h2>Featured Tech Categories</h2>
                        </div>
                        <div className="category-header-actions">
                            <span className="category-scroll-hint">
                                <Sparkles size={13} /> Auto-sliding • Pause on
                                hover
                            </span>
                            <Link
                                href={ROUTES.SHOP}
                                className="view-all-text-link"
                            >
                                View All <ArrowRight size={14} />
                            </Link>
                        </div>
                    </div>

                    <div className="category-marquee-wrapper">
                        <div className="category-bubbles-marquee-track">
                            {/* Duplicate items for seamless infinite continuous sliding */}
                            {[...categoryBubbles, ...categoryBubbles].map(
                                (cat, idx) => (
                                    <Link
                                        href={ROUTES.SHOP_CATEGORY(cat.slug)}
                                        key={`${cat.slug}-${idx}`}
                                        className="category-bubble-card card-hover"
                                    >
                                        <div
                                            className="bubble-icon-circle"
                                            style={{
                                                background: `${cat.color || '#D12127'}15`,
                                                color: cat.color || '#D12127',
                                            }}
                                        >
                                            {getCategoryIcon(cat, { size: 24 })}
                                        </div>
                                        <span className="bubble-name">
                                            {cat.name}
                                        </span>
                                        <span className="bubble-count">
                                            {cat.count || 'Browse'}
                                        </span>
                                    </Link>
                                ),
                            )}
                        </div>
                    </div>
                </section>

                {/* 4. LIVE FLASH SALE (REUSABLE PRODUCT CARD) */}
                <section className="container section-gap">
                    <div className="flash-sale-hero-container">
                        {/* Header with Live Ticking Countdown */}
                        <div className="flash-sale-header-bar">
                            <div className="flash-header-left-box">
                                <div className="flash-sale-live-badge">
                                    <Flame
                                        size={18}
                                        className="flame-icon-pulse"
                                    />
                                    <span>FLASH DEALS</span>
                                </div>
                                <CountdownTimer
                                    label="ENDING IN:"
                                    variant="default"
                                    showIcon={false}
                                />
                            </div>

                            <Link
                                href={ROUTES.OFFERS}
                                className="btn btn-outline-white btn-sm"
                            >
                                <span>ALL 42 DEALS</span>
                                <ArrowRight size={14} />
                            </Link>
                        </div>

                        {/* High-Impact Flash Product Cards (DRY ProductCard Component) */}
                        <div className="flash-products-grid">
                            {loadingFlash
                                ? [...Array(4)].map((_, i) => (
                                      <ProductCardSkeleton key={i} />
                                  ))
                                : flashSaleProducts.map((product) => (
                                      <ProductCard
                                          key={product.id}
                                          product={product}
                                          variant="flash"
                                          isWishlisted={wishlistIds.includes(
                                              product.id,
                                          )}
                                          onAddToCart={addToCart}
                                          onToggleWishlist={() =>
                                              toggleWishlist(product.id)
                                          }
                                      />
                                  ))}
                        </div>
                    </div>
                </section>

                {/* 5. DYNAMIC HIGH-IMPACT GRAPHICAL PROMOTIONAL SHOWCASES */}
                {(() => {
                    const promoCards = bannersList.filter(
                        (b) =>
                            (b.position === 'promo_side' ||
                                b.position === 'promo_top') &&
                            b.is_active,
                    );

                    const activePromos =
                        promoCards.length > 0
                            ? promoCards
                            : [
                                  {
                                      id: 'p1',
                                      badge: 'CUSTOM RIG',
                                      title: 'BUILD YOUR DREAM RIG',
                                      subtitle:
                                          'Instant Compatibility Checker & Free Express Assembly',
                                      image_path:
                                          '/images/promo_banner_pc_builder.jpg',
                                      link_url: ROUTES.PC_BUILDER,
                                      button_text: 'Build Now',
                                  },
                                  {
                                      id: 'p2',
                                      badge: 'SAVE 35%',
                                      title: 'ULTIMATE PC UPGRADE BUNDLE',
                                      subtitle:
                                          'Samsung 990 PRO NVMe + Corsair Dominator DDR5 + 360mm AIO',
                                      image_path:
                                          '/images/promo_banner_special_deals.jpg',
                                      link_url:
                                          ROUTES.SHOP_CATEGORY('components'),
                                      button_text: 'Shop Bundles',
                                  },
                                  {
                                      id: 'p3',
                                      badge: 'CUSTOMER FIRST',
                                      title: 'OFFICIAL WARRANTY CLAIM',
                                      subtitle:
                                          'Doorstep Pickup & Rapid 48H Diagnostic Turnaround',
                                      image_path:
                                          '/images/promo_banner_warranty.jpg',
                                      link_url: ROUTES.STORES,
                                      button_text: 'Get Service',
                                  },
                              ];

                    return (
                        <section className="container section-gap">
                            <div className="graphical-promos-grid">
                                {activePromos.map((promo, pIdx) => (
                                    <div
                                        key={promo.id || pIdx}
                                        className="graphical-promo-banner card-hover"
                                        style={{
                                            backgroundImage: `url(${promo.image_path || promo.image})`,
                                            backgroundSize: 'cover',
                                            backgroundPosition: 'center',
                                        }}
                                    >
                                        <div className="promo-overlay-dark"></div>
                                        <div className="promo-content-layer">
                                            {promo.badge && (
                                                <span className="promo-pill-badge badge-hot">
                                                    {promo.badge}
                                                </span>
                                            )}
                                            <h3>{promo.title}</h3>
                                            {promo.subtitle && (
                                                <p>{promo.subtitle}</p>
                                            )}
                                            <Link
                                                href={
                                                    promo.link_url ||
                                                    ROUTES.SHOP
                                                }
                                                className="btn btn-primary btn-sm mt-3"
                                            >
                                                {promo.button_text || 'Explore'}{' '}
                                                <ArrowRight size={14} />
                                            </Link>
                                        </div>
                                    </div>
                                ))}
                            </div>
                        </section>
                    );
                })()}

                {/* 6. BEST SELLING HARDWARE (WITH TABBED FILTERS & REUSABLE PRODUCTCARD) */}
                <section className="container section-gap">
                    <div className="section-title-row">
                        <div className="title-text-group">
                            <span className="section-pill-tag">
                                TOP HARDWARE
                            </span>
                            <h2>Best Selling Technology</h2>
                        </div>

                        {/* Category Filter Tabs (100% Dynamic from DB Categories) */}
                        <div className="category-filter-tabs">
                            <Tabs
                                tabs={[
                                    { key: 'all', label: 'All Products' },
                                    ...categoryBubbles
                                        .slice(0, 6)
                                        .map((cat) => ({
                                            key: cat.slug,
                                            label: cat.name,
                                        })),
                                ]}
                                activeTab={activeTab}
                                onChange={setActiveTab}
                                variant="pills"
                            />
                        </div>
                    </div>

                    {loadingBestSellers ? (
                        <div className="standard-products-grid">
                            {[...Array(4)].map((_, i) => (
                                <ProductCardSkeleton key={i} />
                            ))}
                        </div>
                    ) : bestSellersList.length > 0 ? (
                        <div className="standard-products-grid">
                            {bestSellersList.map((product) => (
                                <ProductCard
                                    key={product.id}
                                    product={product}
                                    variant="standard"
                                    isWishlisted={wishlistIds.includes(
                                        product.id,
                                    )}
                                    onAddToCart={addToCart}
                                    onToggleWishlist={() =>
                                        toggleWishlist(product.id)
                                    }
                                />
                            ))}
                        </div>
                    ) : (
                        <EmptyState
                            message="No best-selling hardware found in this category."
                            actionLabel="View All Products"
                            onAction={() => setActiveTab('all')}
                        />
                    )}
                </section>

                {/* 7. INTERACTIVE PC BUILDER MINI-STUDIO WIDGET (100% Dynamic DB Driven) */}
                {(() => {
                    const selectedCpu =
                        builderSpecs?.cpu?.[builderCpu] ||
                        Object.values(builderSpecs?.cpu || {})[0];
                    const selectedGpu =
                        builderSpecs?.gpu?.[builderGpu] ||
                        Object.values(builderSpecs?.gpu || {})[0];
                    const selectedRam =
                        builderSpecs?.ram?.[builderRam] ||
                        Object.values(builderSpecs?.ram || {})[0];

                    const cpuPrice = selectedCpu?.price || 0;
                    const gpuPrice = selectedGpu?.price || 0;
                    const ramPrice = selectedRam?.price || 0;

                    /*
                     * The three parts, and nothing else.
                     *
                     * A flat ৳32,000 for "the rest of the system" used to be
                     * added here, unlabelled, so this panel and the builder it
                     * hands over to quoted different totals for the same three
                     * choices — ৳3,97,000 against ৳3,65,000. A made-up number
                     * nobody can see is worse than no number; the builder is
                     * where a real total lives, and the caption now says what
                     * this one covers.
                     */
                    const builderTotal = cpuPrice + gpuPrice + ramPrice;

                    const estimatedWattage =
                        (selectedCpu?.wattage || 125) +
                        (selectedGpu?.wattage || 250) +
                        (selectedRam?.wattage || 15) +
                        65;
                    const recommendedPsu =
                        estimatedWattage > 650 ? '850W+ Gold' : '650W+ Bronze';

                    return (
                        <section className="container section-gap">
                            <div className="pc-builder-interactive-panel">
                                <div className="builder-panel-header">
                                    <div>
                                        <span className="section-pill-tag text-primary">
                                            INSTANT CONFIGURATOR
                                        </span>
                                        <h2>Build Your Dream PC Online</h2>
                                        <p>
                                            Select key components and see live
                                            estimated power consumption and
                                            pricing in real time.
                                        </p>
                                    </div>
                                    <Link
                                        href={ROUTES.PC_BUILDER}
                                        className="btn btn-primary"
                                    >
                                        OPEN FULL PC BUILDER{' '}
                                        <ArrowRight size={16} />
                                    </Link>
                                </div>

                                {/*
                                 * Three component rows, not three columns of
                                 * buttons.
                                 *
                                 * The columns were labelled STEP 1/2/3 though
                                 * all three were on screen at once and could
                                 * be answered in any order, and each held a
                                 * stack of full-width option buttons — so a
                                 * part with four choices left a tall column
                                 * beside one with two, and the panel was
                                 * ragged whatever was in stock. A row per
                                 * component with a select reads at a glance,
                                 * takes the same space however many options
                                 * there are, and is the shape the full builder
                                 * uses.
                                 */}
                                <div className="builder-interactive-grid">
                                    <div className="builder-parts">
                                        {[
                                            {
                                                key: 'cpu',
                                                label: 'Processor',
                                                icon: Cpu,
                                                options: builderSpecs?.cpu,
                                                value: builderCpu,
                                                onPick: setBuilderCpu,
                                                chosen: selectedCpu,
                                            },
                                            {
                                                key: 'gpu',
                                                label: 'Graphics card',
                                                icon: MonitorPlay,
                                                options: builderSpecs?.gpu,
                                                value: builderGpu,
                                                onPick: setBuilderGpu,
                                                chosen: selectedGpu,
                                            },
                                            {
                                                key: 'ram',
                                                label: 'Memory',
                                                icon: MemoryStick,
                                                options: builderSpecs?.ram,
                                                value: builderRam,
                                                onPick: setBuilderRam,
                                                chosen: selectedRam,
                                            },
                                        ].map(
                                            ({
                                                key,
                                                label,
                                                icon: Icon,
                                                options,
                                                value,
                                                onPick,
                                                chosen,
                                            }) => (
                                                <div
                                                    key={key}
                                                    className="builder-part-row"
                                                >
                                                    <span className="builder-part-icon">
                                                        <Icon size={18} />
                                                    </span>

                                                    <div className="builder-part-main">
                                                        <label
                                                            className="builder-part-label"
                                                            htmlFor={`pick-${key}`}
                                                        >
                                                            {label}
                                                        </label>
                                                        <select
                                                            id={`pick-${key}`}
                                                            className="builder-part-select"
                                                            value={value || ''}
                                                            onChange={(e) =>
                                                                onPick(
                                                                    e.target
                                                                        .value,
                                                                )
                                                            }
                                                        >
                                                            {Object.entries(
                                                                options || {},
                                                            ).map(
                                                                ([
                                                                    optKey,
                                                                    item,
                                                                ]) => (
                                                                    <option
                                                                        key={
                                                                            optKey
                                                                        }
                                                                        value={
                                                                            optKey
                                                                        }
                                                                    >
                                                                        {
                                                                            item.name
                                                                        }
                                                                    </option>
                                                                ),
                                                            )}
                                                        </select>
                                                    </div>

                                                    <span className="builder-part-price">
                                                        {formatBdt(
                                                            chosen?.price || 0,
                                                        )}
                                                    </span>
                                                </div>
                                            ),
                                        )}
                                    </div>

                                    {/* Estimated Summary Column */}
                                    <div className="builder-summary-card">
                                        <div className="summary-badge">
                                            <Zap size={14} /> LIVE SUMMARY
                                        </div>

                                        <div className="summary-stat-box">
                                            <span>Estimated Power Draw</span>
                                            <strong>
                                                {estimatedWattage}W (
                                                {recommendedPsu})
                                            </strong>
                                        </div>

                                        <div className="summary-stat-box">
                                            <span>Compatibility Check</span>
                                            <strong className="text-emerald">
                                                <Check size={14} /> 100%
                                                Guaranteed
                                            </strong>
                                        </div>

                                        <div className="summary-total-price">
                                            <span>These three parts</span>
                                            <h2>{formatBdt(builderTotal)}</h2>
                                            <small>
                                                Board, storage, power supply and
                                                case still to choose
                                            </small>
                                        </div>

                                        <Link
                                            href={`${ROUTES.PC_BUILDER}?cpu=${selectedCpu?.id || ''}&gpu=${selectedGpu?.id || ''}&ram=${selectedRam?.id || ''}`}
                                            className="btn btn-primary w-100 mt-3"
                                        >
                                            FINALIZE THIS RIG{' '}
                                            <ArrowRight size={16} />
                                        </Link>
                                    </div>
                                </div>
                            </div>
                        </section>
                    );
                })()}

                {/* 8. EXPERIENCE SHOWROOMS & OUTLETS (100% Dynamic from Admin) */}
                <section className="container section-gap">
                    <div className="showrooms-banner-card card-hover">
                        <div className="showrooms-content-left">
                            <span className="section-pill-tag">
                                VISIT OUR SHOWROOMS
                            </span>
                            <h2>
                                Experience High-End Tech Live at{' '}
                                {storesList.length > 0 ? storesList.length : 15}
                                + Locations
                            </h2>
                            <p>
                                Test flagship gaming rigs, try mechanical
                                switches, and get hands-on consultation from
                                certified system engineers.
                            </p>

                            <div className="branches-pills-grid">
                                {storesList.length > 0 ? (
                                    storesList.slice(0, 6).map((store) => (
                                        <Link
                                            key={store.id}
                                            href={ROUTES.STORES}
                                            className="branch-pill"
                                        >
                                            <span className="live-dot"></span>
                                            <strong>{store.name}</strong> (
                                            {store.city})
                                        </Link>
                                    ))
                                ) : (
                                    <div className="branch-pill">
                                        <span className="live-dot"></span>
                                        <strong>
                                            IDB Bhaban Flagship
                                        </strong>{' '}
                                        (Dhaka)
                                    </div>
                                )}
                            </div>

                            <div className="showroom-cta-row">
                                <Link
                                    href={ROUTES.STORES}
                                    className="btn btn-primary"
                                >
                                    <MapPin size={16} /> FIND NEAREST SHOWROOM
                                </Link>
                                <a
                                    href={`tel:${siteConfig.hotline}`}
                                    className="btn btn-outline"
                                >
                                    <Headset size={16} /> CALL{' '}
                                    {siteConfig.hotline} (9AM - 8PM)
                                </a>
                            </div>
                        </div>

                        <div className="showrooms-graphic-right">
                            <div className="graphic-badge-box">
                                <strong>
                                    {storesList.length > 0
                                        ? storesList.length
                                        : '15'}
                                    +
                                </strong>
                                <span>Official Outlets Across Bangladesh</span>
                            </div>
                        </div>
                    </div>
                </section>

                {/* 9. TOP BRAND ECOSYSTEM LOGO MARQUEE */}
                <section className="container section-gap">
                    <div className="brands-marquee-wrapper">
                        <div className="brands-marquee-header">
                            <span className="marquee-badge">
                                DIRECT FROM MANUFACTURERS
                            </span>
                            <h3>AUTHORISED BRAND PARTNERS</h3>
                        </div>
                        <BrandMarquee />
                    </div>
                </section>

                {/* 10. TECH JOURNAL & BUYING GUIDES (100% Dynamic DB Articles) */}
                <section className="container section-gap">
                    <div className="section-title-row">
                        <div className="title-text-group">
                            <span className="section-pill-tag">
                                TECH JOURNAL
                            </span>
                            <h2>Latest Tech News &amp; Buying Guides</h2>
                        </div>
                        <Link
                            href={ROUTES.BLOGS}
                            className="view-all-text-link"
                        >
                            View All Articles <ArrowRight size={15} />
                        </Link>
                    </div>

                    <div className="tech-journal-grid">
                        {blogsList.map((article) => (
                            <div
                                key={article.id}
                                className="journal-card card-hover"
                            >
                                <Link
                                    href={
                                        article.slug
                                            ? ROUTES.BLOG_DETAIL(article.slug)
                                            : ROUTES.BLOGS
                                    }
                                    className="journal-img-box"
                                    style={{
                                        backgroundImage: `url(${article.image_path || article.image || '/images/hero_banner_beast_pc.jpg'})`,
                                        backgroundSize: 'cover',
                                        backgroundPosition: 'center',
                                        display: 'block',
                                    }}
                                >
                                    <span className="journal-category-tag">
                                        {article.category || 'HARDWARE'}
                                    </span>
                                </Link>
                                <div className="journal-body">
                                    <span className="journal-date">
                                        <Clock size={12} />{' '}
                                        {article.published_at
                                            ? new Date(
                                                  article.published_at,
                                              ).toLocaleDateString('en-GB', {
                                                  day: 'numeric',
                                                  month: 'short',
                                                  year: 'numeric',
                                              })
                                            : '20 Aug 2026'}{' '}
                                        • {article.read_time || '5 min read'}
                                    </span>
                                    <h4>
                                        <Link
                                            href={
                                                article.slug
                                                    ? ROUTES.BLOG_DETAIL(
                                                          article.slug,
                                                      )
                                                    : ROUTES.BLOGS
                                            }
                                            style={{
                                                color: 'inherit',
                                                textDecoration: 'none',
                                            }}
                                        >
                                            {article.title}
                                        </Link>
                                    </h4>
                                    <p>{article.excerpt || article.summary}</p>
                                    <Link
                                        href={
                                            article.slug
                                                ? ROUTES.BLOG_DETAIL(
                                                      article.slug,
                                                  )
                                                : ROUTES.BLOGS
                                        }
                                        className="read-more-link"
                                    >
                                        Read Full Guide &rarr;
                                    </Link>
                                </div>
                            </div>
                        ))}
                    </div>
                </section>
            </div>
        </>
    );
}

// Persistent shell: mounts once, survives navigation.
Welcome.layout = mainLayout;
