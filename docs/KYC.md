# KYC documents (EP2)

Merchants and riders upload identity, licence and bank documents; an admin
reviews them and verifies or rejects the account.

## Where files live

A **private S3 bucket**. These are Aadhaar, PAN and bank records — they must
never be publicly readable, and they need to survive the EC2 instance being
replaced.

Only metadata is stored in MySQL. The object key is never returned by the API:
with the key and the bucket name, anyone could construct a direct URL and
bypass the link expiry entirely. Reads go through **signed URLs valid for 5
minutes**, regenerated each time the document is fetched.

### Bucket setup

Create the bucket in the **same region as the EC2 instance** (`ap-southeast-1`)
so transfers stay in-region and free.

1. S3 → Create bucket → `nexmile-documents`, General purpose
2. **Block all public access** — leave every box ticked
3. **Bucket Versioning: Enable**, so a replaced document can still be produced
   if a dispute reaches back years
4. **Default encryption: SSE-S3** with Bucket Key enabled
5. Object Lock: disabled

### Credentials — IAM role, not access keys

The application runs on EC2, so it takes credentials from an instance role.
**There is no access key anywhere**: nothing to leak, rotate, or accidentally
commit.

Policy `NexmileDocumentsAccess`:

```json
{
  "Version": "2012-10-17",
  "Statement": [{
    "Effect": "Allow",
    "Action": ["s3:PutObject", "s3:GetObject", "s3:DeleteObject"],
    "Resource": "arn:aws:s3:::nexmile-documents/*"
  }]
}
```

Three actions on one bucket. Even with the server fully compromised, that is
the entire blast radius — it cannot list buckets, read other buckets, or delete
the bucket itself.

Attach it to a role trusted by **EC2** (`NexmileAppRole`), then
**EC2 → Instance → Actions → Security → Modify IAM role**. Effective within
seconds; no restart.

```env
AWS_ACCESS_KEY_ID=
AWS_SECRET_ACCESS_KEY=
AWS_DEFAULT_REGION=ap-southeast-1
AWS_BUCKET=nexmile-documents
AWS_USE_PATH_STYLE_ENDPOINT=false
KYC_DISK=s3
```

**Leave the two key lines empty.** Laravel only passes explicit credentials
when both are set; otherwise the AWS SDK falls back to the instance role.

Set `KYC_DISK=local` for development to keep files under `storage/app`.

Verify:

```bash
php artisan tinker --execute="Storage::disk('s3')->put('healthcheck.txt','ok'); echo Storage::disk('s3')->get('healthcheck.txt'),PHP_EOL; Storage::disk('s3')->delete('healthcheck.txt'); echo 'S3 working',PHP_EOL;"
```

If you ever run the app off EC2, you will need an IAM user with access keys
instead — the role only works on AWS compute.

### The S3 driver package

`league/flysystem-aws-s3-v3` must be installed, or every upload fails with
`PortableVisibilityConverter not found`. It is in `composer.json`, so a normal
`composer install` covers it — but note that `Storage::fake('s3')` in tests
does **not** load the real adapter, so the test suite cannot catch a missing
package.

## Required documents

| Merchant | Rider |
|---|---|
| FSSAI certificate | Aadhaar (front and back) |
| PAN card | PAN card |
| Bank proof | Driving licence |
| *GST certificate (optional)* | Vehicle RC |
| *Shopfront photo (optional)* | Vehicle insurance |

Configured in `config/kyc.php`.

## Endpoints

### Merchant and rider

| Method | Path | Purpose |
|---|---|---|
| GET | `/v1/{merchant\|rider}/kyc` | status, what is uploaded, what is missing |
| POST | `/v1/{merchant\|rider}/kyc/documents` | upload (multipart: `type`, `file`) |
| DELETE | `/v1/{merchant\|rider}/kyc/documents/{id}` | remove a pending or rejected document |
| POST | `/v1/{merchant\|rider}/kyc/submit` | submit for review |
| PATCH | `/v1/rider/kyc/details` | licence, RC, insurance and bank numbers |

Uploads are **JPG, PNG or PDF up to 10 MB**. Send as `multipart/form-data`, not
JSON.

### Server limits must stay above the app limit

PHP discards a file larger than `upload_max_filesize` *before* Laravel runs any
validation, so the size rule never fires and the merchant sees Laravel's
default `uploaded` message — "The file failed to upload." — which explains
nothing. Nginx is lower still and answers with a bare `413` page.

Whichever limit is lowest is the one that produces the error, and only ours can
describe itself. So the server must always sit above `max_file_size_kb`:

| Limit | Where | Value |
|---|---|---|
| `max_file_size_kb` | `config/kyc.php` | 10 MB |
| `upload_max_filesize` | `/etc/php/8.5/fpm/conf.d/99-nexmile.ini` | 12M |
| `post_max_size` | same file | 16M |
| `client_max_body_size` | `/etc/nginx/sites-available/nexmile` | 12M |

Ubuntu ships `upload_max_filesize` at **2M**, which rejects an ordinary scanned
FSSAI certificate. Verify with `php-fpm8.5 -i`, not `php -i` — the CLI reads a
different ini file and will report the wrong number.

The status response tells the client exactly what to ask for next:

```json
{
  "data": {
    "status": "pending",
    "missing_documents": ["pan_card", "bank_proof"],
    "can_submit": false,
    "documents": [
      {
        "id": 1,
        "type": "fssai_certificate",
        "label": "FSSAI licence certificate",
        "status": "pending",
        "download_url": "https://...signed...",
        "rejection_reason": null
      }
    ]
  }
}
```

### Admin

| Method | Path | Purpose |
|---|---|---|
| GET | `/v1/admin/kyc/queue` | accounts awaiting review, oldest first |
| GET | `/v1/admin/kyc/{merchants\|riders}/{id}/documents` | one account's documents |
| POST | `/v1/admin/kyc/documents/{id}/review` | approve or reject one document |
| POST | `/v1/admin/kyc/{merchants\|riders}/{id}/verify` | verify the account |
| POST | `/v1/admin/kyc/{merchants\|riders}/{id}/reject` | reject with a reason |
| POST | `/v1/admin/kyc/{merchants\|riders}/{id}/status` | suspend or reinstate |

## Rules

**Re-uploading a type replaces the old file.** Otherwise an applicant
accumulates three PAN cards and the reviewer has to guess which is current.

**Approved documents cannot be deleted or replaced.** They are the evidence
behind a verification decision.

**An account cannot be verified while any document is rejected** — the record
would contradict itself.

**Rejections require a reason** (minimum 10 characters for an account). It is
shown to the applicant, so "no" helps nobody.

**Every decision records who made it and when.** "Who approved this restaurant"
has to be answerable.

**Object keys are random UUIDs, not the uploaded filename.** User-supplied
names can carry path separators and leak personal information into the key.

**Verifying activates the user account.** A merchant still has to open their
storefront and a rider still has to go on duty — verification grants
permission, it does not act on their behalf.

**Suspending a merchant immediately stops orders and revokes their tokens**,
rather than waiting until they next open the dashboard.

## Document types a role may upload

A merchant cannot attach a driving licence and a rider cannot attach an FSSAI
certificate. The reviewer's checklist depends on types matching the role, and
a mismatched document would sit in the queue unexplained.
