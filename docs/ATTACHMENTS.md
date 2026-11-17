# Attachments for the Postar API (request for the service)

The live `POST /v1/send` (https://postar.vip/llm.txt, `openapi.json`) accepts `from`, `name`, `reply_to`, `to`, `subject`, `html`, `text`. It has no attachments and no embedded (`cid:`) images, so apps cannot mail PDF reports or inline logos. `rapttor/yii2-postar` is ready: with `POSTAR_ATTACHMENTS=send` it posts the field below. **The service must accept it first**; until then keep the default (`skip-inline`).

## Proposed field

`attachments` (optional, array, max total 10 MB decoded; limit configurable per project):

| key | required | notes |
|---|---|---|
| `filename` | yes | no path, no line breaks |
| `content_type` | yes | e.g. `application/pdf`, `image/png` |
| `content` | yes | base64 |
| `inline` | no | `true` = embedded image, shown in the html |
| `cid` | with `inline` | the id used as `<img src="cid:logo">` |

Errors: `422` with `code: attachments_too_large` / `attachment_invalid` (do not retry); executables (`.exe`, `.js`, `.bat`, ...) refused to protect the sending domain's reputation.

## Text for postar.vip/llm.txt (paste into "Send an email")

> | attachments | no | array of `{filename, content_type, content (base64)}`; for an image shown in the html add `inline: true` and `cid` (use `<img src="cid:<cid>">`). Total size limit 10 MB. Not for bulk mail. |

And in the PHP/Yii2 section: "`rapttor/yii2-postar` sends Symfony `attach()` / `embed()` parts as `attachments` when `POSTAR_ATTACHMENTS=send`."

## Plugin side

| `POSTAR_ATTACHMENTS` | behaviour |
|---|---|
| `send` | attachments and embedded images go in the payload |
| `skip-inline` (default) | embedded images dropped with a logged warning, real attachments throw |
| `skip` | everything dropped with a logged warning |
| `throw` | any attachment or embedded image throws |

The mail queue stores attachments (JSON, base64) in the `attachments` column (migration `m261117_000001_add_emailqueue_attachments`).
