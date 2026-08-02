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

1. S3 → Create bucket → `nexmile-documents`
2. **Block all public access** — leave every box ticked
3. Enable **Default encryption** (SSE-S3 is enough)
4. Enable **Versioning**, so a replaced document can still be produced if a
   dispute reaches back years
5. IAM → create a user with programmatic access and this policy only:

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

Scoped to one bucket and three actions. If the key leaks, the damage is
bounded — it cannot list buckets, read other buckets, or delete the bucket.

```env
AWS_ACCESS_KEY_ID=...
AWS_SECRET_ACCESS_KEY=...
AWS_DEFAULT_REGION=ap-southeast-1
AWS_BUCKET=nexmile-documents
KYC_DISK=s3
```

Set `KYC_DISK=local` for development to keep files under `storage/app`.

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

Uploads are **JPG, PNG or PDF up to 5 MB**. Send as `multipart/form-data`, not
JSON.

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
