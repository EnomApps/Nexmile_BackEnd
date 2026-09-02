<?php

use App\Enums\DocumentType;

return [

    /*
     * Where KYC documents are stored. These are identity and financial
     * records, so the disk must be private — never the public one.
     */
    'disk' => env('KYC_DISK', 's3'),

    /*
     * How long a download link stays valid. Short, because the link grants
     * access to an Aadhaar or bank document to anyone holding it.
     */
    'link_ttl_minutes' => 5,

    /*
     * Keep this below the server's upload_max_filesize (12M) and Nginx's
     * client_max_body_size (12M). PHP discards an oversized file before
     * Laravel runs any rule, so whichever limit is lowest is the one that
     * produces the error message — and only this one can explain itself.
     *
     * 10 MB because merchants photograph documents on phones and scan
     * multi-page licences; 5 MB rejected ordinary FSSAI certificates.
     */
    'max_file_size_kb' => 10240,

    'allowed_mimes' => ['jpg', 'jpeg', 'png', 'pdf'],

    /*
     * Documents that must be uploaded before an account can be submitted for
     * review. Anything not listed here is optional.
     */
    /*
     * How a rider gets around. Walking counts: inside a kilometre it is a
     * real way to work, and the ones who need it most are often the ones
     * without a licence.
     */
    'vehicle_types' => ['walk', 'bicycle', 'motorcycle', 'scooter', 'ev'],

    'required' => [
        'merchant' => [
            DocumentType::FssaiCertificate->value,
            DocumentType::PanCard->value,
            DocumentType::BankProof->value,
        ],
        'rider' => [
            DocumentType::AadhaarFront->value,
            DocumentType::AadhaarBack->value,
            DocumentType::PanCard->value,
            DocumentType::DrivingLicence->value,
            DocumentType::VehicleRc->value,
            DocumentType::VehicleInsurance->value,
        ],

        /*
         * A rider on foot or on a bicycle has no licence, no registration and
         * no vehicle insurance, because none of those exist for them. Asking
         * anyway would not be strict — it would make the option unusable and
         * leave people stuck in onboarding with nothing to upload.
         */
        'rider_unmotorised' => [
            DocumentType::AadhaarFront->value,
            DocumentType::AadhaarBack->value,
            DocumentType::PanCard->value,
        ],
    ],

    /*
     * Document types each role is allowed to upload at all, so a rider cannot
     * attach an FSSAI certificate or vice versa.
     */
    'allowed' => [
        'merchant' => [
            DocumentType::FssaiCertificate->value,
            DocumentType::GstCertificate->value,
            DocumentType::PanCard->value,
            DocumentType::BankProof->value,
            DocumentType::ShopPhoto->value,
        ],
        'rider' => [
            DocumentType::AadhaarFront->value,
            DocumentType::AadhaarBack->value,
            DocumentType::PanCard->value,
            DocumentType::DrivingLicence->value,
            DocumentType::VehicleRc->value,
            DocumentType::VehicleInsurance->value,
            DocumentType::BankProof->value,
            DocumentType::ProfilePhoto->value,
        ],
    ],

];
