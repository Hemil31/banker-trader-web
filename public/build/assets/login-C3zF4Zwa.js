import { n as e } from './rolldown-runtime-CbXtAM7H.js';
import {
    a as t,
    f as n,
    i as r,
    o as i,
    s as a,
    t as o,
} from './utils-D7vFs7oC.js';
import { i as s, n as c, r as l } from './wayfinder-D1vVM27W.js';
import { t as u } from './checkbox-B5IL5Axg.js';
import { t as d } from './spinner-Dv0N6rHe.js';
import { o as f } from './app-CYkRgsN7.js';
import { n as p, t as m } from './label-DtDo4iC-.js';
var h = s(`EyeOff`, [
        [
            `path`,
            {
                d: `M10.733 5.076a10.744 10.744 0 0 1 11.205 6.575 1 1 0 0 1 0 .696 10.747 10.747 0 0 1-1.444 2.49`,
                key: `ct8e1f`,
            },
        ],
        [`path`, { d: `M14.084 14.158a3 3 0 0 1-4.242-4.242`, key: `151rxh` }],
        [
            `path`,
            {
                d: `M17.479 17.499a10.75 10.75 0 0 1-15.417-5.151 1 1 0 0 1 0-.696 10.75 10.75 0 0 1 4.446-5.143`,
                key: `13bj9a`,
            },
        ],
        [`path`, { d: `m2 2 20 20`, key: `1ooewy` }],
    ]),
    g = s(`Eye`, [
        [
            `path`,
            {
                d: `M2.062 12.348a1 1 0 0 1 0-.696 10.75 10.75 0 0 1 19.876 0 1 1 0 0 1 0 .696 10.75 10.75 0 0 1-19.876 0`,
                key: `1nclc0`,
            },
        ],
        [`circle`, { cx: `12`, cy: `12`, r: `3`, key: `1v7zrd` }],
    ]),
    _ = t(),
    v = e(n(), 1),
    y = r();
function b(e) {
    let t = (0, _.c)(20),
        n,
        r,
        i;
    t[0] === e
        ? ((n = t[1]), (r = t[2]), (i = t[3]))
        : (({ className: n, ref: i, ...r } = e),
          (t[0] = e),
          (t[1] = n),
          (t[2] = r),
          (t[3] = i));
    let [a, s] = (0, v.useState)(!1),
        c = a ? `text` : `password`,
        l;
    t[4] === n ? (l = t[5]) : ((l = o(`pr-10`, n)), (t[4] = n), (t[5] = l));
    let u;
    t[6] !== r || t[7] !== i || t[8] !== c || t[9] !== l
        ? ((u = (0, y.jsx)(f, { type: c, className: l, ref: i, ...r })),
          (t[6] = r),
          (t[7] = i),
          (t[8] = c),
          (t[9] = l),
          (t[10] = u))
        : (u = t[10]);
    let d;
    t[11] === Symbol.for(`react.memo_cache_sentinel`)
        ? ((d = () => s(x)), (t[11] = d))
        : (d = t[11]);
    let p = a ? `Hide password` : `Show password`,
        m;
    t[12] === a
        ? (m = t[13])
        : ((m = a
              ? (0, y.jsx)(h, { className: `size-4` })
              : (0, y.jsx)(g, { className: `size-4` })),
          (t[12] = a),
          (t[13] = m));
    let b;
    t[14] !== p || t[15] !== m
        ? ((b = (0, y.jsx)(`button`, {
              type: `button`,
              onClick: d,
              className: `text-muted-foreground hover:text-foreground focus-visible:ring-ring absolute inset-y-0 right-0 flex items-center rounded-r-md px-3 focus-visible:ring-[3px] focus-visible:outline-none`,
              'aria-label': p,
              tabIndex: -1,
              children: m,
          })),
          (t[14] = p),
          (t[15] = m),
          (t[16] = b))
        : (b = t[16]);
    let S;
    return (
        t[17] !== u || t[18] !== b
            ? ((S = (0, y.jsxs)(`div`, {
                  className: `relative`,
                  children: [u, b],
              })),
              (t[17] = u),
              (t[18] = b),
              (t[19] = S))
            : (S = t[19]),
        S
    );
}
function x(e) {
    return !e;
}
var S = (e) => ({ url: S.url(e), method: `post` });
((S.definition = { methods: [`post`], url: `/login` }),
    (S.url = (e) => S.definition.url + c(e)),
    (S.post = (e) => ({ url: S.url(e), method: `post` })));
var C = (e) => ({ action: S.url(e), method: `post` });
((C.post = (e) => ({ action: S.url(e), method: `post` })),
    (S.form = C),
    Object.assign(S, S));
function w(e) {
    let t = (0, _.c)(6),
        { status: n } = e,
        r;
    t[0] === Symbol.for(`react.memo_cache_sentinel`)
        ? ((r = (0, y.jsx)(a, { title: `Log in` })), (t[0] = r))
        : (r = t[0]);
    let o;
    t[1] === Symbol.for(`react.memo_cache_sentinel`)
        ? ((o = (0, y.jsx)(i, {
              ...S.form(),
              resetOnSuccess: [`password`],
              className: `flex flex-col gap-6`,
              children: T,
          })),
          (t[1] = o))
        : (o = t[1]);
    let s;
    t[2] === n
        ? (s = t[3])
        : ((s =
              n &&
              (0, y.jsx)(`div`, {
                  className: `mb-4 text-center text-sm font-medium text-green-600`,
                  children: n,
              })),
          (t[2] = n),
          (t[3] = s));
    let c;
    return (
        t[4] === s
            ? (c = t[5])
            : ((c = (0, y.jsxs)(y.Fragment, { children: [r, o, s] })),
              (t[4] = s),
              (t[5] = c)),
        c
    );
}
function T(e) {
    let { processing: t, errors: n } = e;
    return (0, y.jsx)(y.Fragment, {
        children: (0, y.jsxs)(`div`, {
            className: `grid gap-6`,
            children: [
                (0, y.jsxs)(`div`, {
                    className: `grid gap-2`,
                    children: [
                        (0, y.jsx)(m, {
                            htmlFor: `email`,
                            children: `Email address`,
                        }),
                        (0, y.jsx)(f, {
                            id: `email`,
                            type: `email`,
                            name: `email`,
                            required: !0,
                            autoFocus: !0,
                            tabIndex: 1,
                            autoComplete: `email`,
                            placeholder: `email@example.com`,
                        }),
                        (0, y.jsx)(p, { message: n.email }),
                    ],
                }),
                (0, y.jsxs)(`div`, {
                    className: `grid gap-2`,
                    children: [
                        (0, y.jsx)(`div`, {
                            className: `flex items-center`,
                            children: (0, y.jsx)(m, {
                                htmlFor: `password`,
                                children: `Password`,
                            }),
                        }),
                        (0, y.jsx)(b, {
                            id: `password`,
                            name: `password`,
                            required: !0,
                            tabIndex: 2,
                            autoComplete: `current-password`,
                            placeholder: `Password`,
                        }),
                        (0, y.jsx)(p, { message: n.password }),
                    ],
                }),
                (0, y.jsxs)(`div`, {
                    className: `flex items-center space-x-3`,
                    children: [
                        (0, y.jsx)(u, {
                            id: `remember`,
                            name: `remember`,
                            tabIndex: 3,
                        }),
                        (0, y.jsx)(m, {
                            htmlFor: `remember`,
                            children: `Remember me`,
                        }),
                    ],
                }),
                (0, y.jsxs)(l, {
                    type: `submit`,
                    className: `mt-4 w-full`,
                    tabIndex: 4,
                    disabled: t,
                    'data-test': `login-button`,
                    children: [t && (0, y.jsx)(d, {}), `Log in`],
                }),
            ],
        }),
    });
}
w.layout = {
    title: `Log in to your account`,
    description: `Enter your email and password below to log in`,
};
export { w as default };
