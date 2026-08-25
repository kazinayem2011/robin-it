import React, { useState, useEffect } from 'react';
import { Link } from '@inertiajs/react';
import MainLayout from '../../Layouts/MainLayout';
import SEOHead from '../../Components/SEOHead';
import { Tabs } from '../../Components';
import { blogService } from '../../services';
import { ROUTES } from '../../constants/endpoints';
import siteConfig from '../../constants/siteConfig';
import {
    BookOpen,
    Search,
    Clock,
    User,
    Calendar,
    ArrowRight,
    Flame,
    Cpu,
    ShieldCheck,
} from 'lucide-react';
import './Blogs.css';

const CATEGORIES = [
    { key: 'all', label: 'All Articles' },
    { key: 'Buying Guide', label: 'Buying Guides' },
    { key: 'Hardware Review', label: 'Hardware Reviews' },
    { key: 'Benchmark & Overclocking', label: 'Benchmarks' },
    { key: 'PC Building Guide', label: 'PC Building' },
    { key: 'Industry News', label: 'Tech News' },
];

export default function BlogsIndex() {
    const [blogs, setBlogs] = useState([]);
    const [loading, setLoading] = useState(true);
    const [activeCategory, setActiveCategory] = useState('all');
    const [searchQuery, setSearchQuery] = useState('');

    useEffect(() => {
        setLoading(true);
        const params = {};
        if (activeCategory !== 'all') params.category = activeCategory;

        blogService
            .getBlogs(params)
            .then((data) => {
                if (data && Array.isArray(data)) {
                    setBlogs(data);
                }
            })
            .catch(() => {})
            .finally(() => setLoading(false));
    }, [activeCategory]);

    const filteredBlogs = blogs.filter(
        (b) =>
            b.title.toLowerCase().includes(searchQuery.toLowerCase()) ||
            (b.excerpt || '').toLowerCase().includes(searchQuery.toLowerCase()),
    );

    const featuredLeadStory =
        filteredBlogs.length > 0 ? filteredBlogs[0] : null;
    const remainingArticles =
        filteredBlogs.length > 1 ? filteredBlogs.slice(1) : [];

    return (
        <MainLayout>
            <SEOHead
                title="Tech Journal, Hardware Reviews & Buying Guides"
                description="Read in-depth GPU benchmarks, PC building tutorials, thermal optimization guides, and buying advice from Robin IT hardware engineers."
            />

            {/* Tech Journal Hero Header */}
            <section className="journal-hero-banner">
                <div className="container journal-hero-inner">
                    <div className="journal-hero-title-group">
                        <div className="badge-pill">
                            <BookOpen size={13} /> {siteConfig.name} Tech
                            Journal
                        </div>
                        <h1>Hardware Insights &amp; Buying Guides</h1>
                        <p>
                            Authored by certified systems engineers and
                            overclocking specialists to help you build and
                            configure with 100% confidence.
                        </p>
                    </div>

                    <div className="journal-search-pillbox">
                        <Search size={16} className="text-gray-400" />
                        <input
                            type="text"
                            placeholder="Search articles & benchmarks..."
                            value={searchQuery}
                            onChange={(e) => setSearchQuery(e.target.value)}
                        />
                    </div>
                </div>
            </section>

            <div className="container">
                {/* Category Navigation Strip */}
                <div style={{ marginBottom: '32px' }}>
                    <Tabs
                        tabs={CATEGORIES}
                        activeTab={activeCategory}
                        onChange={setActiveCategory}
                        variant="pills"
                    />
                </div>

                {loading ? (
                    <div
                        className="loading-state-box"
                        style={{ padding: '60px', textAlign: 'center' }}
                    >
                        <div className="spinner-large"></div>
                        <p
                            style={{
                                marginTop: '16px',
                                color: 'var(--gray-500)',
                                fontWeight: 600,
                            }}
                        >
                            Loading latest hardware journals &amp; guides...
                        </p>
                    </div>
                ) : filteredBlogs.length === 0 ? (
                    <div
                        className="empty-state-card"
                        style={{
                            padding: '60px',
                            textAlign: 'center',
                            background: '#fff',
                            border: '1px solid var(--border-color)',
                            borderRadius: 'var(--radius-md)',
                        }}
                    >
                        <BookOpen
                            size={48}
                            className="text-primary"
                            style={{ margin: '0 auto 16px' }}
                        />
                        <h3>No Articles Found</h3>
                        <p style={{ color: 'var(--gray-600)' }}>
                            No technical articles matched your search query. Try
                            another keyword.
                        </p>
                    </div>
                ) : (
                    <>
                        {/* Featured Lead Story */}
                        {featuredLeadStory && (
                            <Link
                                href={ROUTES.BLOG_DETAIL(
                                    featuredLeadStory.slug,
                                )}
                                className="featured-story-card"
                            >
                                <img
                                    src={featuredLeadStory.image_path}
                                    alt={featuredLeadStory.title}
                                    className="featured-story-thumb"
                                    onError={(e) => {
                                        e.currentTarget.src =
                                            '/images/hero_banner_beast_pc.jpg';
                                    }}
                                />
                                <div className="featured-story-content">
                                    <span className="featured-lead-badge">
                                        <Flame
                                            size={13}
                                            style={{
                                                display: 'inline',
                                                marginRight: 4,
                                            }}
                                        />
                                        Featured Benchmark
                                    </span>
                                    <h2>{featuredLeadStory.title}</h2>
                                    <p>{featuredLeadStory.excerpt}</p>
                                    <div className="story-meta-row">
                                        <span>
                                            <User
                                                size={14}
                                                style={{
                                                    display: 'inline',
                                                    marginRight: 4,
                                                }}
                                            />
                                            {featuredLeadStory.author_name}
                                        </span>
                                        <span>•</span>
                                        <span>
                                            <Clock
                                                size={14}
                                                style={{
                                                    display: 'inline',
                                                    marginRight: 4,
                                                }}
                                            />
                                            {featuredLeadStory.read_time}
                                        </span>
                                        <span>•</span>
                                        <span>
                                            <Calendar
                                                size={14}
                                                style={{
                                                    display: 'inline',
                                                    marginRight: 4,
                                                }}
                                            />
                                            {featuredLeadStory.created_at
                                                ? new Date(
                                                      featuredLeadStory.created_at,
                                                  ).toLocaleDateString()
                                                : 'Recent'}
                                        </span>
                                    </div>
                                </div>
                            </Link>
                        )}

                        {/* Remaining Articles Grid */}
                        {remainingArticles.length > 0 && (
                            <div className="journal-articles-grid">
                                {remainingArticles.map((art) => (
                                    <Link
                                        key={art.id}
                                        href={ROUTES.BLOG_DETAIL(art.slug)}
                                        className="journal-article-card"
                                    >
                                        <div className="article-card-thumb-wrap">
                                            <img
                                                src={art.image_path}
                                                alt={art.title}
                                                className="article-card-thumb"
                                                loading="lazy"
                                                onError={(e) => {
                                                    e.currentTarget.src =
                                                        '/images/hero_banner_beast_pc.jpg';
                                                }}
                                            />
                                            <span className="article-card-category-badge">
                                                {art.category}
                                            </span>
                                        </div>
                                        <div className="article-card-body">
                                            <h3>{art.title}</h3>
                                            <p>{art.excerpt}</p>
                                            <div className="article-card-footer">
                                                <span className="article-card-author">
                                                    {art.author_name}
                                                </span>
                                                <span>
                                                    <Clock
                                                        size={12}
                                                        style={{
                                                            display: 'inline',
                                                            marginRight: 4,
                                                        }}
                                                    />
                                                    {art.read_time}
                                                </span>
                                            </div>
                                        </div>
                                    </Link>
                                ))}
                            </div>
                        )}
                    </>
                )}
            </div>
        </MainLayout>
    );
}
