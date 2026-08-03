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
