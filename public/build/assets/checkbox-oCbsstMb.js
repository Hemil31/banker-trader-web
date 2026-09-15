import { n as e } from './rolldown-runtime-CbXtAM7H.js';
import { a as t, f as n, i as r, t as i } from './utils-D7vFs7oC.js';
import { c as a, r as o } from './wayfinder-DPJui1gF.js';
import { c as s, d as c, l, o as u, s as d, u as f } from './app-agYMoroD.js';
var p = o(`Check`, [[`path`, { d: `M20 6 9 17l-5-5`, key: `1gmf2c` }]]),
    m = t(),
    h = e(n(), 1),
    g = r(),
    _ = Object.defineProperty,
    v = (e, t) => _(e, `name`, { value: t, configurable: !0 }),
    y = `Checkbox`,
    [b, x] = f(y),
    [S, C] = b(y);
function w(e) {
    let {
            __scopeCheckbox: t,
            checked: n,
            children: r,
            defaultChecked: i,
            disabled: a,
            form: o,
            name: s,
            onCheckedChange: c,
            required: l,
            value: d = `on`,
            internal_do_not_use_render: f,
        } = e,
        [p, m] = u({ prop: n, defaultProp: i ?? !1, onChange: c, caller: y }),
        [_, v] = h.useState(null),
        [b, x] = h.useState(null),
        C = h.useRef(!1),
        [w, T] = h.useReducer((e) => e + 1, 0),
        E = !_ || !!o || !!_.closest(`form`),
        D = {
            checked: p,
            disabled: a,
            setChecked: m,
            control: _,
            setControl: v,
            name: s,
            form: o,
            value: d,
            hasConsumerStoppedPropagationRef: C,
            userInteractionCount: w,
            onUserInteraction: T,
            required: l,
            defaultChecked: !N(i) && i,
            isFormControl: E,
            bubbleInput: b,
            setBubbleInput: x,
        };
    return (0, g.jsx)(S, { scope: t, ...D, children: M(f) ? f(D) : r });
}
v(w, `CheckboxProvider`);
var T = `CheckboxTrigger`,
    E = h.forwardRef(
        v(function ({ __scopeCheckbox: e, onKeyDown: t, onClick: n, ...r }, i) {
            let {
                    control: o,
                    value: s,
                    disabled: u,
                    checked: d,
                    required: f,
                    setControl: p,
                    setChecked: m,
                    hasConsumerStoppedPropagationRef: _,
                    onUserInteraction: y,
                    isFormControl: b,
                    bubbleInput: x,
                } = C(T, e),
                S = a(i, p),
                w = h.useRef(d);
            return (
                h.useEffect(() => {
                    let e = o?.form;
                    if (e) {
                        let t = v(() => m(w.current), `reset`);
                        return (
                            e.addEventListener(`reset`, t),
                            () => e.removeEventListener(`reset`, t)
                        );
                    }
                }, [o, m]),
                (0, g.jsx)(l.button, {
                    type: `button`,
                    role: `checkbox`,
                    'aria-checked': N(d) ? `mixed` : d,
                    'aria-required': f,
                    'data-state': P(d),
                    'data-disabled': u ? `` : void 0,
                    disabled: u,
                    value: s,
                    ...r,
                    ref: S,
                    onKeyDown: c(t, (e) => {
                        e.key === `Enter` && e.preventDefault();
                    }),
                    onClick: c(n, (e) => {
                        (y(),
                            m((e) => (N(e) ? !0 : !e)),
                            x &&
                                b &&
                                ((_.current = e.isPropagationStopped()),
                                _.current || e.stopPropagation()));
                    }),
                })
            );
        }, `CheckboxTrigger`),
    ),
    D = h.forwardRef(
        v(function (e, t) {
            let {
                __scopeCheckbox: n,
                name: r,
                checked: i,
                defaultChecked: a,
                required: o,
                disabled: s,
                value: c,
                onCheckedChange: l,
                form: u,
                ...d
            } = e;
            return (0, g.jsx)(w, {
                __scopeCheckbox: n,
                checked: i,
                defaultChecked: a,
                disabled: s,
                required: o,
                onCheckedChange: l,
                name: r,
                form: u,
                value: c,
                internal_do_not_use_render: ({ isFormControl: e }) =>
                    (0, g.jsxs)(g.Fragment, {
                        children: [
                            (0, g.jsx)(E, { ...d, ref: t, __scopeCheckbox: n }),
                            e && (0, g.jsx)(j, { __scopeCheckbox: n }),
                        ],
                    }),
            });
        }, `Checkbox`),
    ),
    O = `CheckboxIndicator`,
    k = h.forwardRef(
        v(function (e, t) {
            let { __scopeCheckbox: n, forceMount: r, ...i } = e,
                a = C(O, n);
            return (0, g.jsx)(d, {
                present: r || N(a.checked) || a.checked === !0,
                children: (0, g.jsx)(l.span, {
                    'data-state': P(a.checked),
                    'data-disabled': a.disabled ? `` : void 0,
                    ...i,
                    ref: t,
                    style: { pointerEvents: `none`, ...e.style },
                }),
            });
        }, `CheckboxIndicator`),
    ),
    A = `CheckboxBubbleInput`,
    j = h.forwardRef(
        v(function ({ __scopeCheckbox: e, onClick: t, ...n }, r) {
            let {
                    control: i,
                    hasConsumerStoppedPropagationRef: o,
                    userInteractionCount: u,
                    checked: d,
                    defaultChecked: f,
                    required: p,
                    disabled: m,
                    name: _,
                    value: v,
                    form: y,
                    bubbleInput: b,
                    setBubbleInput: x,
                } = C(A, e),
                S = a(r, x),
                w = s(i),
                T = h.useRef(!1),
                E = h.useRef(d),
                D = h.useRef(u);
            h.useEffect(() => {
                let e = b;
                if (!e) return;
                let t = window.HTMLInputElement.prototype,
                    n = Object.getOwnPropertyDescriptor(t, `checked`).set,
                    r = u !== D.current;
                D.current = u;
                let i = E.current !== d;
                E.current = d;
                let a = !(r && o.current);
                if (i && n) {
                    T.current = !r;
                    let t = new Event(`click`, { bubbles: a });
                    ((e.indeterminate = N(d)),
                        n.call(e, !N(d) && d),
                        e.dispatchEvent(t),
                        (T.current = !1));
                }
            }, [b, d, o, u]);
            let O = h.useRef(!N(d) && d);
            return (0, g.jsx)(l.input, {
                type: `checkbox`,
                'aria-hidden': !0,
                defaultChecked: f ?? O.current,
                required: p,
                disabled: m,
                name: _,
                value: v,
                form: y,
                ...n,
                tabIndex: -1,
                ref: S,
                onClick: c(t, (e) => {
                    T.current && e.stopPropagation();
                }),
                style: {
                    ...n.style,
                    ...w,
                    position: `absolute`,
                    pointerEvents: `none`,
                    opacity: 0,
                    margin: 0,
                    transform: `translateX(-100%)`,
                },
            });
        }, `CheckboxBubbleInput`),
    );
function M(e) {
    return typeof e == `function`;
}
v(M, `isFunction`);
function N(e) {
    return e === `indeterminate`;
}
v(N, `isIndeterminate`);
function P(e) {
    return N(e) ? `indeterminate` : e ? `checked` : `unchecked`;
}
v(P, `getState`);
function F(e) {
    let t = (0, m.c)(9),
        n,
        r;
    t[0] === e
        ? ((n = t[1]), (r = t[2]))
        : (({ className: n, ...r } = e), (t[0] = e), (t[1] = n), (t[2] = r));
    let a;
    t[3] === n
        ? (a = t[4])
        : ((a = i(
              `peer border-input data-[state=checked]:bg-primary data-[state=checked]:text-primary-foreground data-[state=checked]:border-primary focus-visible:border-ring focus-visible:ring-ring/50 aria-invalid:ring-destructive/20 dark:aria-invalid:ring-destructive/40 aria-invalid:border-destructive size-4 shrink-0 rounded-[4px] border shadow-xs transition-shadow outline-none focus-visible:ring-[3px] disabled:cursor-not-allowed disabled:opacity-50`,
              n,
          )),
          (t[3] = n),
          (t[4] = a));
    let o;
    t[5] === Symbol.for(`react.memo_cache_sentinel`)
        ? ((o = (0, g.jsx)(k, {
              'data-slot': `checkbox-indicator`,
              className: `flex items-center justify-center text-current transition-none`,
              children: (0, g.jsx)(p, { className: `size-3.5` }),
          })),
          (t[5] = o))
        : (o = t[5]);
    let s;
    return (
        t[6] !== r || t[7] !== a
            ? ((s = (0, g.jsx)(D, {
                  'data-slot': `checkbox`,
                  className: a,
                  ...r,
                  children: o,
              })),
              (t[6] = r),
              (t[7] = a),
              (t[8] = s))
            : (s = t[8]),
        s
    );
}
export { F as t };
