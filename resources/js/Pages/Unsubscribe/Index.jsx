import React from 'react';
import { Head, Link } from '@inertiajs/react';
import { mainLayout } from '../../Layouts/MainLayout';
import { MailX, ArrowRight } from 'lucide-react';
import Button from '../../Components/Button';
import siteConfig from '../../constants/siteConfig';
import { ROUTES } from '../../constants/endpoints';
import './Unsubscribe.css';

/**
 * @param done Whether a subscriber was found for that link. A stale link and a
 *             made-up one read the same, so the page never says whether an
 *             address was on the list.
 */
export default function Unsubscribe({ done = false, email = null }) {
    return (
        <>
            <Head title={`Unsubscribed — ${siteConfig.name}`} />

            <div className="unsub-page container">
                <div className="unsub-card">
                    <span className="unsub-icon">
                        <MailX size={22} />
                    </span>

                    <h1>You're off the list</h1>

                    <p>
                        {done && email ? (
                            <>
                                We won't email <strong>{email}</strong> about
                                offers again. Order and delivery emails still
                                come through — those are about things you asked
                                us to send.
                            </>
                        ) : (
                            <>
                                That link has already been used, or it has
                                expired. Either way, nothing more will be sent
                                from it.
                            </>
                        )}
                    </p>

                    <Link href={ROUTES.HOME}>
                        <Button variant="secondary" icon={ArrowRight}>
                            Back to the shop
                        </Button>
                    </Link>
                </div>
            </div>
        </>
    );
}

Unsubscribe.layout = mainLayout;
