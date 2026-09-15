import { n as e } from './rolldown-runtime-CbXtAM7H.js';
import { a as t, f as n, i as r, o as i, s as a } from './utils-D7vFs7oC.js';
import { n as o } from './wayfinder-DPJui1gF.js';
import { t as s } from './checkbox-oCbsstMb.js';
import { t as c } from './spinner-wiRFlGFK.js';
import { a as l, n as u, t as d } from './app-agYMoroD.js';
import { a as f, i as p, n as m, r as h, t as g } from './card-y6orrvbC.js';
import { n as _, t as v } from './label-B30W1_zK.js';
var y = t(),
    b = e(n(), 1),
    x = r(),
    S = [
        `Asia/Kolkata`,
        `UTC`,
        `Asia/Dubai`,
        `Asia/Singapore`,
        `Asia/Tokyo`,
        `Europe/London`,
        `America/New_York`,
        `America/Los_Angeles`,
        `Australia/Sydney`,
    ];
function C() {
    let e = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);
    return e ? decodeURIComponent(e[1]) : ``;
}
function w(e, t) {
    return t.includes(`image/gif`)
        ? `gif`
        : t.startsWith(`image/`)
          ? `image`
          : t.startsWith(`video/`)
            ? `video`
            : t === `application/pdf`
              ? `document`
              : null;
}
function T(e) {
    switch (e.status) {
        case `published`:
            return `bg-green-500/10 text-green-600`;
        case `scheduled`:
            return `bg-blue-500/10 text-blue-600`;
        case `failed`:
            return `bg-destructive/10 text-destructive`;
        default:
            return `bg-muted text-muted-foreground`;
    }
}
function E(e) {
    let t = (0, y.c)(17),
        { posts: n, accounts: r, timezone: s } = e,
        T;
    t[0] === Symbol.for(`react.memo_cache_sentinel`)
        ? ((T = []), (t[0] = T))
        : (T = t[0]);
    let [E, O] = (0, b.useState)(T),
        [N, P] = (0, b.useState)(!1),
        [F, I] = (0, b.useState)(null),
        L;
    t[1] === Symbol.for(`react.memo_cache_sentinel`)
        ? ((L = async (e) => {
              (P(!0), I(null));
              let t = [];
              for (let n of e) {
                  let e = w(n.name, n.type);
                  if (e === null) {
                      I(`Only images, videos, GIFs and PDFs are supported.`);
                      continue;
                  }
                  let r = await fetch(d().url, {
                          method: `POST`,
                          headers: {
                              'Content-Type': `application/json`,
                              Accept: `application/json`,
                              'X-XSRF-TOKEN': C(),
                              'X-Requested-With': `XMLHttpRequest`,
                          },
                          body: JSON.stringify({
                              filename: n.name,
                              content_type: n.type,
                              size: n.size,
                          }),
                      }),
                      i;
                  try {
                      i = await r.json();
                  } catch {
                      I(`Could not prepare the upload target.`);
                      continue;
                  }
                  if (!r.ok || !i.upload_url || !i.public_url) {
                      I(`Could not prepare the upload target.`);
                      continue;
                  }
                  if (
                      !(
                          await fetch(i.upload_url, {
                              method: `PUT`,
                              headers: { 'Content-Type': n.type },
                              body: n,
                          })
                      ).ok
                  ) {
                      I(`Could not upload the file.`);
                      continue;
                  }
                  t.push({ url: i.public_url, type: e, name: n.name });
              }
              (O((e) => [...e, ...t]), P(!1));
          }),
          (t[1] = L))
        : (L = t[1]);
    let R = L,
        z;
    t[2] === Symbol.for(`react.memo_cache_sentinel`)
        ? ((z = (0, x.jsx)(a, { title: `Zernio posts` })), (t[2] = z))
        : (z = t[2]);
    let B;
    t[3] === Symbol.for(`react.memo_cache_sentinel`)
        ? ((B = (0, x.jsxs)(`div`, {
              children: [
                  (0, x.jsx)(`h1`, {
                      className: `text-xl font-semibold`,
                      children: `Zernio posts`,
                  }),
                  (0, x.jsx)(`p`, {
                      className: `text-muted-foreground text-sm`,
                      children: `Compose a post and send it to every selected account.`,
                  }),
              ],
          })),
          (t[3] = B))
        : (B = t[3]);
    let V;
    t[4] === Symbol.for(`react.memo_cache_sentinel`)
        ? ((V = u.form()), (t[4] = V))
        : (V = t[4]);
    let H;
    t[5] !== r || t[6] !== E || t[7] !== s || t[8] !== F || t[9] !== N
        ? ((H = (0, x.jsx)(i, {
              ...V,
              resetOnSuccess: !0,
              className: `grid gap-6`,
              children: (e) => {
                  let { processing: t, errors: n, recentlySuccessful: i } = e;
                  return (0, x.jsx)(x.Fragment, {
                      children: (0, x.jsxs)(g, {
                          children: [
                              (0, x.jsxs)(p, {
                                  children: [
                                      (0, x.jsx)(f, {
                                          children: `Compose post`,
                                      }),
                                      (0, x.jsx)(h, {
                                          children: `One post, delivered to all selected accounts.`,
                                      }),
                                  ],
                              }),
                              (0, x.jsxs)(m, {
                                  className: `grid gap-4`,
                                  children: [
                                      (0, x.jsxs)(`div`, {
                                          className: `grid gap-2`,
                                          children: [
                                              (0, x.jsx)(v, {
                                                  htmlFor: `content`,
                                                  children: `Content`,
                                              }),
                                              (0, x.jsx)(`textarea`, {
                                                  id: `content`,
                                                  name: `content`,
                                                  required: !0,
                                                  rows: 4,
                                                  maxLength: 2e3,
                                                  placeholder: `What would you like to post?`,
                                                  className: `border-input focus-visible:border-ring focus-visible:ring-ring/50 dark:bg-input/30 dark:hover:bg-input/50 rounded-md border bg-transparent px-3 py-2 text-sm shadow-xs transition-[color,box-shadow] outline-none focus-visible:ring-[3px] disabled:cursor-not-allowed disabled:opacity-50`,
                                              }),
                                              (0, x.jsx)(_, {
                                                  message: n.content,
                                              }),
                                          ],
                                      }),
                                      (0, x.jsxs)(`div`, {
                                          className: `grid gap-2`,
                                          children: [
                                              (0, x.jsx)(v, {
                                                  children: `Accounts`,
                                              }),
                                              r.length === 0
                                                  ? (0, x.jsx)(`p`, {
                                                        className: `text-muted-foreground text-sm`,
                                                        children: `No accounts mirrored yet. Refresh accounts on the accounts page.`,
                                                    })
                                                  : (0, x.jsx)(`div`, {
                                                        className: `grid gap-2`,
                                                        children: r
                                                            .filter(M)
                                                            .map(j),
                                                    }),
                                              (0, x.jsx)(_, {
                                                  message: n.account_ids,
                                              }),
                                          ],
                                      }),
                                      (0, x.jsxs)(`div`, {
                                          className: `grid gap-4 sm:grid-cols-2`,
                                          children: [
                                              (0, x.jsxs)(`div`, {
                                                  className: `grid gap-2`,
                                                  children: [
                                                      (0, x.jsx)(v, {
                                                          htmlFor: `scheduled_at`,
                                                          children: `Schedule (leave empty to post now)`,
                                                      }),
                                                      (0, x.jsx)(l, {
                                                          id: `scheduled_at`,
                                                          name: `scheduled_at`,
                                                          type: `datetime-local`,
                                                      }),
                                                      (0, x.jsx)(_, {
                                                          message:
                                                              n.scheduled_at,
                                                      }),
                                                  ],
                                              }),
                                              (0, x.jsxs)(`div`, {
                                                  className: `grid gap-2`,
                                                  children: [
                                                      (0, x.jsx)(v, {
                                                          htmlFor: `timezone`,
                                                          children: `Timezone`,
                                                      }),
                                                      (0, x.jsx)(`select`, {
                                                          id: `timezone`,
                                                          name: `timezone`,
                                                          defaultValue: s,
                                                          className: `border-input dark:bg-input/30 dark:hover:bg-input/50 rounded-md border bg-transparent px-3 py-2 text-sm shadow-xs outline-none`,
                                                          children: S.map(A),
                                                      }),
                                                      (0, x.jsx)(_, {
                                                          message: n.timezone,
                                                      }),
                                                  ],
                                              }),
                                          ],
                                      }),
                                      (0, x.jsxs)(`div`, {
                                          className: `grid gap-2`,
                                          children: [
                                              (0, x.jsx)(v, {
                                                  htmlFor: `media`,
                                                  children: `Media`,
                                              }),
                                              (0, x.jsx)(l, {
                                                  id: `media`,
                                                  type: `file`,
                                                  accept: `image/*,video/*,application/pdf,.mp4,.mov,.webm`,
                                                  multiple: !0,
                                                  disabled: N,
                                                  onChange: (e) =>
                                                      void R(
                                                          Array.from(
                                                              e.target.files ??
                                                                  [],
                                                          ),
                                                      ),
                                              }),
                                              N &&
                                                  (0, x.jsxs)(`p`, {
                                                      className: `text-muted-foreground flex items-center gap-2 text-sm`,
                                                      children: [
                                                          (0, x.jsx)(c, {}),
                                                          ` Uploading…`,
                                                      ],
                                                  }),
                                              F &&
                                                  (0, x.jsx)(_, { message: F }),
                                              E.map(k),
                                              (0, x.jsx)(_, {
                                                  message: n.media,
                                              }),
                                          ],
                                      }),
                                      (0, x.jsxs)(`div`, {
                                          className: `flex items-center gap-3`,
                                          children: [
                                              (0, x.jsxs)(o, {
                                                  type: `submit`,
                                                  disabled: t || N,
                                                  'data-test': `create-post`,
                                                  children: [
                                                      t && (0, x.jsx)(c, {}),
                                                      `Send post`,
                                                  ],
                                              }),
                                              i &&
                                                  (0, x.jsx)(`p`, {
                                                      className: `text-sm text-green-600`,
                                                      children: `Sent.`,
                                                  }),
                                          ],
                                      }),
                                  ],
                              }),
                          ],
                      }),
                  });
              },
          })),
          (t[5] = r),
          (t[6] = E),
          (t[7] = s),
          (t[8] = F),
          (t[9] = N),
          (t[10] = H))
        : (H = t[10]);
    let U;
    t[11] === Symbol.for(`react.memo_cache_sentinel`)
        ? ((U = (0, x.jsxs)(p, {
              children: [
                  (0, x.jsx)(f, { children: `Post history` }),
                  (0, x.jsx)(h, {
                      children: `Every post composed from this console.`,
                  }),
              ],
          })),
          (t[11] = U))
        : (U = t[11]);
    let W;
    t[12] === n
        ? (W = t[13])
        : ((W = (0, x.jsxs)(g, {
              children: [
                  U,
                  (0, x.jsx)(m, {
                      children:
                          n.length === 0
                              ? (0, x.jsx)(`p`, {
                                    className: `text-muted-foreground text-sm`,
                                    children: `No posts sent yet.`,
                                })
                              : (0, x.jsx)(`div`, {
                                    className: `grid gap-4`,
                                    children: n.map(D),
                                }),
                  }),
              ],
          })),
          (t[12] = n),
          (t[13] = W));
    let G;
    return (
        t[14] !== H || t[15] !== W
            ? ((G = (0, x.jsxs)(x.Fragment, {
                  children: [
                      z,
                      (0, x.jsxs)(`div`, {
                          className: `flex flex-1 flex-col gap-6 p-4`,
                          children: [B, H, W],
                      }),
                  ],
              })),
              (t[14] = H),
              (t[15] = W),
              (t[16] = G))
            : (G = t[16]),
        G
    );
}
function D(e) {
    return (0, x.jsxs)(
        `div`,
        {
            className: `rounded-md border p-4`,
            children: [
                (0, x.jsxs)(`div`, {
                    className: `flex flex-wrap items-center gap-2`,
                    children: [
                        (0, x.jsx)(`span`, {
                            className: `rounded-full px-2 py-0.5 text-xs ${T(e)}`,
                            children: e.status,
                        }),
                        (0, x.jsxs)(`span`, {
                            className: `text-muted-foreground text-xs`,
                            children: [
                                e.created_by,
                                ` ·`,
                                ` `,
                                e.created_at
                                    ? new Date(e.created_at).toLocaleString()
                                    : `—`,
                            ],
                        }),
                        e.scheduled_at &&
                            (0, x.jsxs)(`span`, {
                                className: `text-muted-foreground text-xs`,
                                children: [
                                    `scheduled for`,
                                    ` `,
                                    new Date(e.scheduled_at).toLocaleString(),
                                    ` `,
                                    `(`,
                                    e.timezone,
                                    `)`,
                                ],
                            }),
                    ],
                }),
                (0, x.jsx)(`p`, {
                    className: `mt-2 text-sm whitespace-pre-wrap`,
                    children: e.content,
                }),
                (0, x.jsx)(`div`, {
                    className: `mt-2 flex flex-wrap gap-2`,
                    children: e.accounts.map(O),
                }),
                e.error &&
                    (0, x.jsx)(`p`, {
                        className: `text-destructive mt-2 text-xs`,
                        children: e.error,
                    }),
            ],
        },
        e.id,
    );
}
function O(e) {
    return (0, x.jsxs)(
        `a`,
        {
            href: e.platform_post_url ?? void 0,
            target: `_blank`,
            rel: `noreferrer`,
            className: `text-muted-foreground bg-muted rounded-full px-2 py-0.5 text-xs`,
            children: [e.platform, `:`, ` `, e.name, ` (`, e.status, `)`],
        },
        e.id,
    );
}
function k(e, t) {
    return (0, x.jsxs)(
        `div`,
        {
            className: `text-muted-foreground text-sm`,
            children: [
                (0, x.jsx)(`input`, {
                    type: `hidden`,
                    name: `media[${t}][url]`,
                    value: e.url,
                }),
                (0, x.jsx)(`input`, {
                    type: `hidden`,
                    name: `media[${t}][type]`,
                    value: e.type,
                }),
                e.name,
            ],
        },
        e.url,
    );
}
function A(e) {
    return (0, x.jsx)(`option`, { value: e, children: e }, e);
}
function j(e) {
    return (0, x.jsxs)(
        `label`,
        {
            className: `flex items-center gap-3 rounded-md border p-3 text-sm`,
            children: [
                (0, x.jsx)(s, {
                    name: `account_ids[]`,
                    value: e.id,
                    defaultChecked: !0,
                }),
                (0, x.jsxs)(`div`, {
                    className: `flex min-w-0 flex-1 items-center gap-2`,
                    children: [
                        (0, x.jsx)(`span`, {
                            className: `font-medium`,
                            children: e.name,
                        }),
                        (0, x.jsx)(`span`, {
                            className: `text-muted-foreground text-xs uppercase`,
                            children: e.platform,
                        }),
                    ],
                }),
            ],
        },
        e.id,
    );
}
function M(e) {
    return e.is_active;
}
export { E as default };
