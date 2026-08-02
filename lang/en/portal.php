<?php

return [

    'register' => [
        'title' => 'Register your restaurant',
        'intro' => 'Takes about five minutes. Our team verifies your documents and activates the account, usually within two working days.',
        'account' => 'Owner account',
        'account_hint' => "You'll use these details to sign in.",
        'business' => 'Business details',
        'business_hint' => 'Your address decides which customers can see you.',
        'submit' => 'Create account',
        'have_account' => 'Already registered?',
    ],

    'login' => [
        'title' => 'Merchant sign in',
        'intro' => 'Manage your documents, menu and orders.',
        'submit' => 'Sign in',
        'remember' => 'Keep me signed in',
        'no_account' => 'New to Nexmile?',
    ],

    'fields' => [
        'owner_name' => 'Owner name',
        'phone' => 'Mobile number',
        'email' => 'Email',
        'password' => 'Password',
        'password_hint' => 'At least 8 characters, with letters and numbers.',
        'password_confirmation' => 'Confirm password',
        'identifier' => 'Mobile number or email',
        'business_name' => 'Restaurant name',
        'address_line1' => 'Address line 1',
        'address_line2' => 'Address line 2',
        'city' => 'City',
        'state' => 'State',
        'pincode' => 'PIN code',
        'language' => 'Preferred language',
        'optional' => 'optional',
    ],

    'dashboard' => [
        'title' => 'Merchant dashboard',
        'signed_in_as' => 'Signed in as',
        'logout' => 'Sign out',
        'business_details' => 'Business details',
        'owner' => 'Owner',
        'mobile' => 'Mobile',
        'email' => 'Email',
        'address' => 'Address',
        'documents' => 'Documents',
        'documents_hint' => 'JPG, PNG or PDF, up to 5 MB each.',
        'upload' => 'Upload',
        'replace' => 'Replace',
        'remove' => 'Remove',
        'required' => 'Required',
        'not_uploaded' => 'Not uploaded',
        'submit_for_review' => 'Submit for verification',
        'submit_hint' => 'Upload every required document before submitting.',
        'next_steps' => 'What happens next',
    ],

    'kyc' => [
        'pending' => 'Not yet submitted',
        'submitted' => 'Awaiting verification',
        'verified' => 'Verified',
        'rejected' => 'Changes needed',
        'pending_message' => 'Upload your documents and submit them for verification.',
        'submitted_message' => 'Our team is reviewing your documents. We usually respond within two working days.',
        'verified_message' => 'Your restaurant is verified. Menu management and orders are coming in the next release.',
        'rejected_message' => 'Something needs fixing before we can verify your account:',
    ],

];
