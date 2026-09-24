/**
 * A Formik change handler that also marks the field touched, for forms that
 * must not change their layout when a field loses focus.
 *
 * The sign-in and sign-up forms validated on blur, and they open with the
 * cursor in their first field. Pressing the mouse on "Forgot Password?" took
 * the focus out of that empty field, which put "…is required" under it and
 * pushed everything below down 12px — so the button came up over empty space
 * and the click was lost. Every link under those forms took two clicks.
 *
 * Touched on change instead, and the field's onBlur left unset: an error
 * appears while someone is typing something wrong, and on submit for anything
 * left empty, but never at the moment focus moves to something else.
 */
export function touchOnChange(formik) {
    return (event) => {
        formik.handleChange(event);
        formik.setFieldTouched(event.target.name, true, false);
    };
}
