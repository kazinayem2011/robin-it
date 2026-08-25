import { useEffect, useRef } from 'react';
import { usePage } from '@inertiajs/react';
import { toast } from '../Components/Toast';

/**
 * Show the messages the server flashes.
 *
 * Controllers say back()->with('success', 'Profile updated successfully.') on
 * nearly every write, and none of it was ever displayed — the prop was not
 * shared and nothing listened for it, so a saved form looked identical to one
 * that had done nothing.
 *
 * Inertia keeps props between visits, so the same message can arrive again on a
 * partial reload. Firing on change rather than on presence keeps one save to
 * one toast.
 */
export const useFlashToasts = () => {
    const flash = usePage().props?.flash ?? {};
    const shown = useRef({});

    useEffect(() => {
        const kinds = {
            success: toast.success,
            error: toast.error,
            warning: toast.warning,
            info: toast.info,
        };

        Object.entries(kinds).forEach(([kind, show]) => {
            const message = flash?.[kind];

            if (message && shown.current[kind] !== message) {
                shown.current[kind] = message;
                show?.(message);
            }

            if (!message) {
                shown.current[kind] = undefined;
            }
        });
    }, [flash]);
};

export default useFlashToasts;
