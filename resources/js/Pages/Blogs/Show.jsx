import Spinner from '@/Components/Spinner';
import React, { useState, useEffect } from 'react';
import { Link } from '@inertiajs/react';
import { mainLayout } from '../../Layouts/MainLayout';
import SEOHead from '../../Components/SEOHead';
import { blogService } from '../../services';
import { ROUTES } from '../../constants/endpoints';
import { toast } from '../../Components/Toast';
import {
    Clock,
    Calendar,
    Share2,
    Check,
    ChevronRight,
    ArrowLeft,
} from 'lucide-react';
import './Blogs.css';

export default function BlogShow({ slug }) {
    const [blog, setBlog] = useState(null);
    const [loading, setLoading] = useState(true);
    const [copied, setCopied] = useState(false);
    const [relatedBlogs, setRelatedBlogs] = useState([]);

    useEffect(() => {
        setLoading(true);
        blogService
            .getBlogBySlug(slug)
            .then((data) => {
                if (data) {
                    setBlog(data);
                }
            })
            .catch(() => {})
            .finally(() => setLoading(false));

        // Fetch other articles for related recommendations
        blogService
            .getBlogs({ limit: 3 })
            .then((data) => {
                if (data && Array.isArray(data)) {
                    setRelatedBlogs(
                        data.filter((b) => b.slug !== slug).slice(0, 2),
                    );
                }
            })
            .catch(() => {});
    }, [slug]);

    const handleCopyShare = () => {
        if (typeof window !== 'undefined') {
            navigator.clipboard.writeText(window.location.href);
            setCopied(true);
            toast.success('Article link copied to clipboard!');
            setTimeout(() => setCopied(false), 3000);
        }
    };

    if (loading) {
        return (
            <>
                <div
                    className="container"
                    style={{ padding: '80px 0', textAlign: 'center' }}
                >
                    <Spinner
                        fullHeight
                        text="Loading Tech Journal Article..."
                    />
                </div>
            </>
        );
    }

    if (!blog) {
        return (
            <>
                <div
                    className="container"
                    style={{ padding: '80px 0', textAlign: 'center' }}
                >
                    <h2>Article Not Found</h2>
                    <p
                        style={{
                            color: 'var(--text-body)',
                            marginBottom: '24px',
                        }}
                    >
                        The requested tech article may have moved or been
                        archived.
                    </p>
                    <Link href={ROUTES.BLOGS} className="btn btn-primary">
                        <ArrowLeft size={16} /> Back to Tech Journal
                    </Link>
                </div>
            </>
        );
    }

    const publishedAt = blog.published_at || blog.created_at;

    return (
        <>
            {/*
             * The same title, picture and type the server put in the HTML, so
             * a reader that runs this (Google) and one that does not (every
             * chat app) are told the same thing. The BlogPosting markup is
             * the server's alone; sending it from here as well would publish
             * the article twice.
             */}
            <SEOHead
                title={blog.title}
                description={blog.excerpt}
                image={blog.image_path}
                type="article"
            />

            <div className="article-reader-wrapper">
                <div className="container article-reader-container">
                    {/* Breadcrumbs */}
                    <div style={{ marginBottom: '20px' }}>
                        <Link href={ROUTES.HOME}>Home</Link>
                        <ChevronRight size={13} />
                        <Link href={ROUTES.BLOGS}>Tech Journal</Link>
                        <ChevronRight size={13} />
                        <span>{blog.category}</span>
                    </div>

                    {/* Article Header */}
                    <div className="article-reader-header">
                        <span className="article-reader-badge">
                            {blog.category}
                        </span>
                        <h1>{blog.title}</h1>
                        <p
                            style={{
                                fontSize: '1.15rem',
                                color: 'var(--text-body)',
                                lineHeight: '1.6',
                            }}
                        >
                            {blog.excerpt}
                        </p>
                    </div>

                    {/* Author & Publication Strip */}
                    <div className="article-author-card-strip">
                        <div className="author-meta-info">
                            <div className="author-avatar">
                                {blog.author_name
                                    ? blog.author_name.charAt(0)
                                    : 'R'}
                            </div>
                            <div>
                                <strong
                                    style={{
                                        display: 'block',
                                        color: 'var(--text-primary)',
                                    }}
                                >
                                    {blog.author_name}
                                </strong>
                                <small style={{ color: 'var(--text-muted)' }}>
                                    {blog.author_role ||
                                        'Systems Engineer & Hardware Specialist'}
                                </small>
                            </div>
                        </div>

                        <div className="story-meta-row">
                            <span className="story-meta-item">
                                <Clock size={14} aria-hidden="true" />
                                {blog.read_time}
                            </span>
                            <span aria-hidden="true">•</span>
                            <span className="story-meta-item">
                                <Calendar size={14} aria-hidden="true" />
                                {/* When it went out, not when the row was
                                    written: the two differed by days. */}
                                {publishedAt ? (
                                    <time dateTime={publishedAt}>
                                        {new Date(
                                            publishedAt,
                                        ).toLocaleDateString()}
                                    </time>
                                ) : (
                                    'Recent'
                                )}
                            </span>
                        </div>
                    </div>

                    {/* Article Graphic */}
                    <img
                        src={blog.image_path}
                        alt={blog.title}
                        className="article-hero-graphic"
                        onError={(e) => {
                            e.currentTarget.src =
                                '/images/hero_banner_beast_pc.jpg';
                        }}
                    />

                    {/* Article Content Body */}
                    <div className="article-rich-body">
                        {blog.content ? (
                            <div
                                dangerouslySetInnerHTML={{
                                    __html: blog.content.replace(
                                        /\n\n/g,
                                        '<p></p>',
                                    ),
                                }}
                            />
                        ) : (
                            <p>{blog.excerpt}</p>
                        )}
                    </div>

                    {/* Share Action Strip */}
                    <div
                        style={{
                            display: 'flex',
                            justifyContent: 'space-between',
                            alignItems: 'center',
                            padding: '20px',
                            background: 'var(--bg-surface-subtle)',
                            borderRadius: 'var(--radius-md)',
                            border: '1px solid var(--border-color)',
                            margin: '48px 0',
                        }}
                    >
                        <div
                            style={{
                                display: 'flex',
                                alignItems: 'center',
                                gap: '8px',
                            }}
                        >
                            <Share2 size={18} className="text-primary" />
                            <strong style={{ fontSize: '0.92rem' }}>
                                Share this guide:
                            </strong>
                        </div>
                        <button
                            type="button"
                            className="btn btn-outline btn-sm"
                            onClick={handleCopyShare}
                        >
                            {copied ? (
                                <Check size={14} />
                            ) : (
                                <Share2 size={14} />
                            )}
                            <span>
                                {copied ? 'Link Copied!' : 'Copy Share Link'}
                            </span>
                        </button>
                    </div>

                    {/* Related Articles Strip */}
                    {relatedBlogs.length > 0 && (
                        <div style={{ marginTop: '56px' }}>
                            <h3
                                style={{
                                    fontSize: '1.4rem',
                                    fontWeight: 800,
                                    marginBottom: '20px',
                                }}
                            >
                                Recommended Reading
                            </h3>
                            <div
                                className="journal-articles-grid"
                                style={{
                                    gridTemplateColumns: 'repeat(2, 1fr)',
                                }}
                            >
                                {relatedBlogs.map((rel) => (
                                    <Link
                                        key={rel.id}
                                        href={ROUTES.BLOG_DETAIL(rel.slug)}
                                        className="journal-article-card"
                                    >
                                        <div className="article-card-thumb-wrap">
                                            <img
                                                src={rel.image_path}
                                                alt={rel.title}
                                                className="article-card-thumb"
                                                onError={(e) => {
                                                    e.currentTarget.src =
                                                        '/images/hero_banner_beast_pc.jpg';
                                                }}
                                            />
                                        </div>
                                        <div className="article-card-body">
                                            <h3>{rel.title}</h3>
                                            <p>{rel.excerpt}</p>
                                        </div>
                                    </Link>
                                ))}
                            </div>
                        </div>
                    )}
                </div>
            </div>
        </>
    );
}

// Persistent shell: mounts once, survives navigation.
BlogShow.layout = mainLayout;
