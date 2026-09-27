/**
 * Today's date as a date box wants it (YYYY-MM-DD), on this device's clock.
 *
 * Not `new Date().toISOString().slice(0, 10)`: that is the date in UTC,
 * which in Bangladesh is still yesterday until six in the morning.
 */
export const localToday = (date = new Date()) => {
    const pad = (n) => String(n).padStart(2, '0');
    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
};
