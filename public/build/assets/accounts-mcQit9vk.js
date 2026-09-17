import { a as e, i as t, o as n, s as r } from './utils-D7vFs7oC.js';
import { r as i } from './wayfinder-D1vVM27W.js';
import { t as a } from './spinner-Dv0N6rHe.js';
import { i as o } from './app-CYkRgsN7.js';
import { a as s, i as c, n as l, r as u, t as d } from './card-y6orrvbC.js';
var f = e(),
    p = t();
function m(e) {
    let t = (0, f.c)(12),
        { accounts: i } = e,
        a;
    t[0] === Symbol.for(`react.memo_cache_sentinel`)
        ? ((a = (0, p.jsx)(r, { title: `Zernio accounts` })), (t[0] = a))
        : (a = t[0]);
    let m;
    t[1] === Symbol.for(`react.memo_cache_sentinel`)
        ? ((m = (0, p.jsxs)(`div`, {
              children: [
                  (0, p.jsx)(`h1`, {
                      className: `text-xl font-semibold`,
                      children: `Zernio accounts`,
                  }),
                  (0, p.jsx)(`p`, {
                      className: `text-muted-foreground text-sm`,
                      children: `Social accounts connected at zernio.com. Sync to pick up new connections.`,
                  }),
              ],
          })),
          (t[1] = m))
        : (m = t[1]);
    let _;
    t[2] === Symbol.for(`react.memo_cache_sentinel`)
        ? ((_ = (0, p.jsxs)(`div`, {
              className: `flex flex-wrap items-start justify-between gap-4`,
              children: [
                  m,
                  (0, p.jsx)(n, {
                      ...o.form(),
                      className: `flex items-end gap-3`,
                      children: g,
                  }),
              ],
          })),
          (t[2] = _))
        : (_ = t[2]);
    let v;
    t[3] === Symbol.for(`react.memo_cache_sentinel`)
        ? ((v = (0, p.jsx)(s, { children: `Connected accounts` })), (t[3] = v))
        : (v = t[3]);
    let y = i.length === 1 ? `` : `s`,
        b;
    t[4] !== i.length || t[5] !== y
        ? ((b = (0, p.jsxs)(c, {
              children: [
                  v,
                  (0, p.jsxs)(u, {
                      children: [
                          i.length,
                          ` account`,
                          y,
                          ` mirrored from Zernio`,
                      ],
                  }),
              ],
          })),
          (t[4] = i.length),
          (t[5] = y),
          (t[6] = b))
        : (b = t[6]);
    let x;
    t[7] === i
        ? (x = t[8])
        : ((x = (0, p.jsx)(l, {
              className: `overflow-x-auto`,
              children:
                  i.length === 0
                      ? (0, p.jsx)(`p`, {
                            className: `text-muted-foreground text-sm`,
                            children: `No accounts yet. Connect accounts at zernio.com, then hit “Refresh accounts”.`,
                        })
                      : (0, p.jsxs)(`table`, {
                            className: `w-full text-sm`,
                            children: [
                                (0, p.jsx)(`thead`, {
                                    children: (0, p.jsxs)(`tr`, {
                                        className: `text-muted-foreground border-b text-left`,
                                        children: [
                                            (0, p.jsx)(`th`, {
                                                className: `pr-4 pb-2 font-medium`,
                                                children: `Account`,
                                            }),
                                            (0, p.jsx)(`th`, {
                                                className: `pr-4 pb-2 font-medium`,
                                                children: `Platform`,
                                            }),
                                            (0, p.jsx)(`th`, {
                                                className: `pr-4 pb-2 font-medium`,
                                                children: `Status`,
                                            }),
                                            (0, p.jsx)(`th`, {
                                                className: `pb-2 font-medium`,
                                                children: `Synced`,
                                            }),
                                        ],
                                    }),
                                }),
                                (0, p.jsx)(`tbody`, { children: i.map(h) }),
                            ],
                        }),
          })),
          (t[7] = i),
          (t[8] = x));
    let S;
    return (
        t[9] !== b || t[10] !== x
            ? ((S = (0, p.jsxs)(p.Fragment, {
                  children: [
                      a,
                      (0, p.jsxs)(`div`, {
                          className: `flex flex-1 flex-col gap-6 p-4`,
                          children: [_, (0, p.jsxs)(d, { children: [b, x] })],
                      }),
                  ],
              })),
              (t[9] = b),
              (t[10] = x),
              (t[11] = S))
            : (S = t[11]),
        S
    );
}
function h(e) {
    return (0, p.jsxs)(
        `tr`,
        {
            className: `border-b last:border-0`,
            children: [
                (0, p.jsxs)(`td`, {
                    className: `py-3 pr-4`,
                    children: [
                        (0, p.jsx)(`p`, {
                            className: `font-medium`,
                            children: e.name,
                        }),
                        e.username &&
                            (0, p.jsxs)(`p`, {
                                className: `text-muted-foreground text-xs`,
                                children: [`@`, e.username],
                            }),
                    ],
                }),
                (0, p.jsx)(`td`, {
                    className: `py-3 pr-4 uppercase`,
                    children: e.platform,
                }),
                (0, p.jsxs)(`td`, {
                    className: `py-3 pr-4`,
                    children: [
                        !e.is_active &&
                            (0, p.jsx)(`span`, {
                                className: `bg-muted text-muted-foreground rounded-full px-2 py-0.5 text-xs`,
                                children: `inactive`,
                            }),
                        e.is_active &&
                            e.needs_reconnection &&
                            (0, p.jsx)(`span`, {
                                className: `bg-destructive/10 text-destructive rounded-full px-2 py-0.5 text-xs`,
                                children: `needs reconnection`,
                            }),
                        e.is_active &&
                            !e.needs_reconnection &&
                            (0, p.jsx)(`span`, {
                                className: `rounded-full bg-green-500/10 px-2 py-0.5 text-xs text-green-600`,
                                children: `active`,
                            }),
                    ],
                }),
                (0, p.jsx)(`td`, {
                    className: `text-muted-foreground py-3`,
                    children: e.synced_at
                        ? new Date(e.synced_at).toLocaleString()
                        : `—`,
                }),
            ],
        },
        e.id,
    );
}
function g(e) {
    let { processing: t } = e;
    return (0, p.jsxs)(i, {
        type: `submit`,
        disabled: t,
        'data-test': `sync-accounts`,
        children: [t && (0, p.jsx)(a, {}), `Refresh accounts`],
    });
}
export { m as default };
