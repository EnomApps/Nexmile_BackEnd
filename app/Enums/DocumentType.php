<?php

namespace App\Enums;

enum DocumentType: string
{
    // Merchant
    case FssaiCertificate = 'fssai_certificate';
    case GstCertificate = 'gst_certificate';
    case ShopPhoto = 'shop_photo';

    // Rider
    case AadhaarFront = 'aadhaar_front';
    case AadhaarBack = 'aadhaar_back';
    case DrivingLicence = 'driving_licence';
    case VehicleRc = 'vehicle_rc';
    case VehicleInsurance = 'vehicle_insurance';
    case ProfilePhoto = 'profile_photo';

    // Both
    case PanCard = 'pan_card';
    case BankProof = 'bank_proof';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function label(): string
    {
        return match ($this) {
            self::FssaiCertificate => 'FSSAI licence certificate',
            self::GstCertificate => 'GST registration certificate',
            self::ShopPhoto => 'Photo of the shopfront',
            self::AadhaarFront => 'Aadhaar card (front)',
            self::AadhaarBack => 'Aadhaar card (back)',
            self::DrivingLicence => 'Driving licence',
            self::VehicleRc => 'Vehicle registration certificate',
            self::VehicleInsurance => 'Vehicle insurance',
            self::ProfilePhoto => 'Profile photo',
            self::PanCard => 'PAN card',
            self::BankProof => 'Cancelled cheque or bank statement',
        };
    }
}
