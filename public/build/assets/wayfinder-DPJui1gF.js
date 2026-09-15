import { n as e } from './rolldown-runtime-CbXtAM7H.js';
import { f as t, i as n, r, t as i } from './utils-D7vFs7oC.js';
var a = e(t(), 1),
    o = Object.defineProperty,
    s = (e, t) => o(e, `name`, { value: t, configurable: !0 });
function c(e, t) {
    if (typeof e == `function`) return e(t);
    e != null && (e.current = t);
}
s(c, `setRef`);
function l(...e) {
    return (t) => {
        let n = !1,
            r = e.map((e) => {
                let r = c(e, t);
                return (!n && typeof r == `function` && (n = !0), r);
            });
        if (n)
            return () => {
                for (let t = 0; t < r.length; t++) {
                    let n = r[t];
                    typeof n == `function` ? n() : c(e[t], null);
                }
            };
    };
}
s(l, `composeRefs`);
function u(...e) {
    return a.useCallback(l(...e), e);
}
s(u, `useComposedRefs`);
var d = Object.defineProperty,
    f = (e, t) => d(e, `name`, { value: t, configurable: !0 });
function p(e) {
    let t = a.forwardRef((t, n) => {
        let { children: r, ...i } = t,
            o = null,
            s = !1,
            c = [];
        (S(r) && typeof E == `function` && (r = E(r._payload)),
            a.Children.forEach(r, (e) => {
                if (b(e)) {
                    s = !0;
                    let t = e,
                        n =
                            `child` in t.props
                                ? t.props.child
                                : t.props.children;
                    (S(n) && typeof E == `function` && (n = E(n._payload)),
                        (o = _(t, n)),
                        c.push(o?.props?.children));
                } else c.push(e);
            }),
            o
                ? (o = a.cloneElement(o, void 0, c))
                : !s &&
                  a.Children.count(r) === 1 &&
                  a.isValidElement(r) &&
                  (o = r));
        let l = o ? y(o) : void 0,
            d = u(n, l);
        if (!o) {
            if (r || r === 0) throw Error(s ? T(e) : w(e));
            return r;
        }
        let f = v(i, o.props ?? {});
        return (
            o.type !== a.Fragment && (f.ref = n ? d : l), a.cloneElement(o, f)
        );
    });
    return ((t.displayName = `${e}.Slot`), t);
}
f(p, `createSlot`);
var m = p(`Slot`),
    h = Symbol.for(`radix.slottable`);
function g(e) {
    let t = f(
        (e) => (`child` in e ? e.children(e.child) : e.children),
        `Slottable`,
    );
    return ((t.displayName = `${e}.Slottable`), (t.__radixId = h), t);
}
f(g, `createSlottable`);
var _ = f((e, t) => {
    if (`child` in e.props) {
        let t = e.props.child;
        return a.isValidElement(t)
            ? a.cloneElement(t, void 0, e.props.children(t.props.children))
            : null;
    }
    return a.isValidElement(t) ? t : null;
}, `getSlottableElementFromSlottable`);
function v(e, t) {
    let n = { ...t };
    for (let r in t) {
        let i = e[r],
            a = t[r];
        /^on[A-Z]/.test(r)
            ? i && a
                ? (n[r] = (...e) => {
                      let t = a(...e);
                      return (i(...e), t);
                  })
                : i && (n[r] = i)
            : r === `style`
              ? (n[r] = { ...i, ...a })
              : r === `className` && (n[r] = [i, a].filter(Boolean).join(` `));
    }
    return { ...e, ...n };
}
f(v, `mergeProps`);
function y(e) {
    let t = Object.getOwnPropertyDescriptor(e.props, `ref`)?.get,
        n = t && `isReactWarning` in t && t.isReactWarning;
    return n
        ? e.ref
        : ((t = Object.getOwnPropertyDescriptor(e, `ref`)?.get),
          (n = t && `isReactWarning` in t && t.isReactWarning),
          n ? e.props.ref : e.props.ref || e.ref);
}
f(y, `getElementRef`);
function b(e) {
    return (
        a.isValidElement(e) &&
        typeof e.type == `function` &&
        `__radixId` in e.type &&
        e.type.__radixId === h
    );
}
f(b, `isSlottable`);
var x = Symbol.for(`react.lazy`);
function S(e) {
    return (
        typeof e == `object` &&
        !!e &&
        `$$typeof` in e &&
        e.$$typeof === x &&
        `_payload` in e &&
        C(e._payload)
    );
}
f(S, `isLazyComponent`);
function C(e) {
    return typeof e == `object` && !!e && `then` in e;
}
f(C, `isPromiseLike`);
var w = f(
        (e) =>
            `${e} failed to slot onto its children. Expected a single React element child or \`Slottable\`.`,
        `createSlotError`,
    ),
    T = f(
        (e) =>
            `${e} failed to slot onto its \`Slottable\`. Expected \`Slottable\` to receive a single React element child.`,
        `createSlottableError`,
    ),
    E = a.use,
    D = (e) => (typeof e == `boolean` ? `${e}` : e === 0 ? `0` : e),
    O = r,
    k = (e, t) => (n) => {
        if (t?.variants == null) return O(e, n?.class, n?.className);
        let { variants: r, defaultVariants: i } = t,
            a = Object.keys(r).map((e) => {
                let t = n?.[e],
                    a = i?.[e];
                if (t === null) return null;
                let o = D(t) || D(a);
                return r[e][o];
            }),
            o =
                n &&
                Object.entries(n).reduce((e, t) => {
                    let [n, r] = t;
                    return (r === void 0 || (e[n] = r), e);
                }, {});
        return O(
            e,
            a,
            t?.compoundVariants?.reduce((e, t) => {
                let { class: n, className: r, ...a } = t;
                return Object.entries(a).every((e) => {
                    let [t, n] = e;
                    return Array.isArray(n)
                        ? n.includes({ ...i, ...o }[t])
                        : { ...i, ...o }[t] === n;
                })
                    ? [...e, n, r]
                    : e;
            }, []),
            n?.class,
            n?.className,
        );
    },
    A = (e) => e.replace(/([a-z0-9])([A-Z])/g, `$1-$2`).toLowerCase(),
    j = (...e) =>
        e
            .filter((e, t, n) => !!e && e.trim() !== `` && n.indexOf(e) === t)
            .join(` `)
            .trim(),
    M = {
        xmlns: `http://www.w3.org/2000/svg`,
        width: 24,
        height: 24,
        viewBox: `0 0 24 24`,
        fill: `none`,
        stroke: `currentColor`,
        strokeWidth: 2,
        strokeLinecap: `round`,
        strokeLinejoin: `round`,
    },
    N = (0, a.forwardRef)(
        (
            {
                color: e = `currentColor`,
                size: t = 24,
                strokeWidth: n = 2,
                absoluteStrokeWidth: r,
                className: i = ``,
                children: o,
                iconNode: s,
                ...c
            },
            l,
        ) =>
            (0, a.createElement)(
                `svg`,
                {
                    ref: l,
                    ...M,
                    width: t,
                    height: t,
                    stroke: e,
                    strokeWidth: r ? (Number(n) * 24) / Number(t) : n,
                    className: j(`lucide`, i),
                    ...c,
                },
                [
                    ...s.map(([e, t]) => (0, a.createElement)(e, t)),
                    ...(Array.isArray(o) ? o : [o]),
                ],
            ),
    ),
    P = (e, t) => {
        let n = (0, a.forwardRef)(({ className: n, ...r }, i) =>
            (0, a.createElement)(N, {
                ref: i,
                iconNode: t,
                className: j(`lucide-${A(e)}`, n),
                ...r,
            }),
        );
        return ((n.displayName = `${e}`), n);
    },
    F = n(),
    I = k(
        `inline-flex items-center justify-center gap-2 whitespace-nowrap rounded-md text-sm font-medium transition-[color,box-shadow] disabled:pointer-events-none disabled:opacity-50 [&_svg]:pointer-events-none [&_svg:not([class*='size-'])]:size-4 [&_svg]:shrink-0 outline-none focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px] aria-invalid:ring-destructive/20 dark:aria-invalid:ring-destructive/40 aria-invalid:border-destructive`,
        {
            variants: {
                variant: {
                    default: `bg-primary text-primary-foreground shadow-xs hover:bg-primary/90`,
                    destructive: `bg-destructive text-white shadow-xs hover:bg-destructive/90 focus-visible:ring-destructive/20 dark:focus-visible:ring-destructive/40`,
                    outline: `border border-input bg-background shadow-xs hover:bg-accent hover:text-accent-foreground`,
                    secondary: `bg-secondary text-secondary-foreground shadow-xs hover:bg-secondary/80`,
                    ghost: `hover:bg-accent hover:text-accent-foreground`,
                    link: `text-primary underline-offset-4 hover:underline`,
                },
                size: {
                    default: `h-9 px-4 py-2 has-[>svg]:px-3`,
                    sm: `h-8 rounded-md px-3 has-[>svg]:px-2.5`,
                    lg: `h-10 rounded-md px-6 has-[>svg]:px-4`,
                    icon: `size-9`,
                },
            },
            defaultVariants: { variant: `default`, size: `default` },
        },
    );
function L({ className: e, variant: t, size: n, asChild: r = !1, ...a }) {
    return (0, F.jsx)(r ? m : `button`, {
        'data-slot': `button`,
        className: i(I({ variant: t, size: n, className: e })),
        ...a,
    });
}
var R = (e) => (e === !0 ? `1` : e === !1 ? `0` : e.toString()),
    z = (e, t, n) => {
        Object.entries(e).forEach(([e, r]) => {
            if (r === void 0) return;
            let i = `${t}[${e}]`;
            Array.isArray(r)
                ? r.forEach((e) => n.append(`${i}[]`, R(e)))
                : typeof r == `object` && r
                  ? z(r, i, n)
                  : [`string`, `number`, `boolean`].includes(typeof r) &&
                    n.set(i, R(r));
        });
    },
    B = (e, t) => {
        let n = new Set();
        (e.forEach((e, r) => {
            (r === t || r.startsWith(`${t}[`)) && n.add(r);
        }),
            n.forEach((t) => e.delete(t)));
    },
    V = (e) => {
        if (!e || (!e.query && !e.mergeQuery)) return ``;
        let t = e.query ?? e.mergeQuery,
            n = e.mergeQuery !== void 0,
            r = new URLSearchParams(
                n && typeof window < `u` ? window.location.search : ``,
            );
        for (let e in t) {
            let i = t[e];
            (n && B(r, e),
                i != null &&
                    (Array.isArray(i)
                        ? i.forEach((t) => {
                              r.append(`${e}[]`, t.toString());
                          })
                        : typeof i == `object`
                          ? z(i, e, r)
                          : r.set(e, R(i))));
        }
        let i = r.toString();
        return i.length > 0 ? `?${i}` : ``;
    };
export { m as a, u as c, k as i, L as n, p as o, P as r, g as s, V as t };
