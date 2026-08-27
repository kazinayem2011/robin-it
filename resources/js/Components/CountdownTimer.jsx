import React, { useState, useEffect, useCallback } from 'react';
import { Clock, Flame, Zap } from 'lucide-react';
import './CountdownTimer.css';

/**
 * Reusable Countdown Timer Component
 *
 * Supports:
 * - targetDate (ISO string or Date object)
 * - durationHours / initialSeconds
 * - variants: 'default' | 'badge' | 'compact' | 'pill' | 'card'
 * - custom label, icons, and expiration callbacks
 */
export default function CountdownTimer({
    targetDate = null,
    label = 'ENDING IN:',
    variant = 'default',
    showIcon = false,
    iconType = 'clock', // 'clock' | 'flame' | 'zap'
    onExpire = null,
    className = '',
}) {
    const calculateTimeLeft = useCallback(() => {
        if (targetDate) {
            const difference = +new Date(targetDate) - +new Date();
            if (difference <= 0) {
                return {
                    days: 0,
                    hours: 0,
                    minutes: 0,
                    seconds: 0,
                    isExpired: true,
                };
            }
            return {
                days: Math.floor(difference / (1000 * 60 * 60 * 24)),
                hours: Math.floor((difference / (1000 * 60 * 60)) % 24),
                minutes: Math.floor((difference / 1000 / 60) % 60),
                seconds: Math.floor((difference / 1000) % 60),
                isExpired: false,
            };
        }

        // Default daily recurring loop timer
        const now = new Date();
        const midnight = new Date();
        midnight.setHours(24, 0, 0, 0);
        const diff = midnight - now;

        return {
            days: 0,
            hours: Math.floor((diff / (1000 * 60 * 60)) % 24),
            minutes: Math.floor((diff / 1000 / 60) % 60),
            seconds: Math.floor((diff / 1000) % 60),
            isExpired: false,
        };
    }, [targetDate]);

    const [timeLeft, setTimeLeft] = useState(calculateTimeLeft);

    useEffect(() => {
        const timer = setInterval(() => {
            const updated = calculateTimeLeft();
            setTimeLeft(updated);
            if (updated.isExpired && onExpire) {
                onExpire();
                clearInterval(timer);
            }
        }, 1000);

        return () => clearInterval(timer);
    }, [calculateTimeLeft, onExpire]);

    const formatNum = (num) => String(num).padStart(2, '0');

    const renderIcon = () => {
        if (!showIcon) return null;
        switch (iconType) {
            case 'flame':
                return <Flame size={15} className="countdown-icon-flame" />;
            case 'zap':
                return <Zap size={15} className="countdown-icon-zap" />;
            default:
                return <Clock size={15} className="countdown-icon-clock" />;
        }
    };

    return (
        <div
            className={`reusable-countdown-timer timer-variant-${variant} ${className}`}
        >
            {renderIcon()}
            {label && <span className="countdown-timer-label">{label}</span>}

            <div className="countdown-digits-wrapper">
                {timeLeft.days > 0 && (
                    <>
                        <div className="countdown-box">
                            <span className="digit-val">
                                {formatNum(timeLeft.days)}
                            </span>
                            <span className="digit-unit">d</span>
                        </div>
                        <span className="countdown-sep">:</span>
                    </>
                )}

                <div className="countdown-box">
                    <span className="digit-val">
                        {formatNum(timeLeft.hours)}
                    </span>
                    <span className="digit-unit">h</span>
                </div>

                <span className="countdown-sep">:</span>

                <div className="countdown-box">
                    <span className="digit-val">
                        {formatNum(timeLeft.minutes)}
                    </span>
                    <span className="digit-unit">m</span>
                </div>

                <span className="countdown-sep">:</span>

                <div className="countdown-box box-accent">
                    <span className="digit-val">
                        {formatNum(timeLeft.seconds)}
                    </span>
                    <span className="digit-unit">s</span>
                </div>
            </div>
        </div>
    );
}
