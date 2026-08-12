<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use Illuminate\View\View;

/**
 * Static marketing pages. Copy lives in config/site.php so it can be
 * updated without touching the templates.
 */
class PageController extends Controller
{
    public function show(string $page): View
    {
        return view("pages.{$page}");
    }

    /**
     * Terms, privacy and refunds.
     *
     * One template, three documents from config/legal.php. They share a shape,
     * and Razorpay requires all three to be publicly linked before it will
     * activate live payments.
     */
    public function legal(string $document): View
    {
        $doc = config("legal.documents.{$document}");

        abort_if($doc === null, 404);

        return view('pages.legal', ['doc' => $doc, 'current' => $document]);
    }
}
