package com.littlelemon.pos;

import android.app.AlertDialog;
import android.content.Context;
import android.os.Bundle;
import android.os.Handler;
import android.os.Looper;
import android.print.PrintAttributes;
import android.print.PrintJob;
import android.print.PrintManager;
import android.webkit.JavascriptInterface;
import android.webkit.WebResourceError;
import android.webkit.WebResourceRequest;
import android.webkit.WebView;
import android.widget.PopupMenu;
import androidx.activity.OnBackPressedCallback;
import com.getcapacitor.BridgeActivity;
import com.getcapacitor.BridgeWebViewClient;
import com.getcapacitor.CapConfig;
import java.net.URI;

public class MainActivity extends BridgeActivity {
    private static final String PRODUCTION_URL = "https://restaurant.mkposmyanmar.com";
    private final String serverUrl = PRODUCTION_URL;
    private boolean showingError;

    @Override
    public void onCreate(Bundle savedInstanceState) {
        WebView.enableSlowWholeDocumentDraw();
        registerPlugin(MkposPrinterPlugin.class);
        config = new CapConfig.Builder(this).setServerUrl(serverUrl).setAndroidScheme("https")
            .setAllowMixedContent(false).setWebContentsDebuggingEnabled(false).create();
        super.onCreate(savedInstanceState);
        if (bridge == null) return;
        WebView webView = bridge.getWebView();
        webView.addJavascriptInterface(new PrintBridge(), "RestaurantNative");
        bridge.setWebViewClient(new BridgeWebViewClient(bridge) {
            @Override
            public void onPageFinished(WebView view, String url) {
                super.onPageFinished(view, url);
                if (sameOrigin(url)) view.evaluateJavascript("window.print=function(){if(window.restaurantPrintCurrentDocument){window.restaurantPrintCurrentDocument().catch(function(){});return;}var p=document.getElementById('printable-receipt');RestaurantNative.print(p?Number(p.dataset.paperWidth):0,p?Math.ceil(p.scrollHeight*25.4/96)+4:0);};", null);
            }
            @Override
            public void onReceivedError(WebView view, WebResourceRequest request, WebResourceError error) {
                super.onReceivedError(view, request, error);
                if (request.isForMainFrame() && !showingError && !isFinishing()) {
                    showingError = true;
                    new AlertDialog.Builder(MainActivity.this).setTitle("Cannot reach restaurant")
                        .setMessage("Check your internet connection and try again.\n" + serverUrl)
                        .setPositiveButton("Retry", (dialog, which) -> { showingError = false; view.loadUrl(serverUrl); })
                        .setNegativeButton("Close", (dialog, which) -> finish())
                        .setOnCancelListener(dialog -> showingError = false).show();
                }
            }
        });
        findViewById(R.id.restaurant_menu).setOnClickListener(view -> {
            PopupMenu menu = new PopupMenu(this, view);
            menu.getMenu().add("Home"); menu.getMenu().add("Reload"); menu.getMenu().add("Printer settings"); menu.getMenu().add("Print");
            menu.setOnMenuItemClickListener(item -> {
                switch (item.getTitle().toString()) {
                    case "Home": webView.loadUrl(serverUrl); break;
                    case "Reload": webView.reload(); break;
                    case "Printer settings": printerAction("openRestaurantPrinterSettings"); break;
                    case "Print": printerAction("restaurantPrintCurrentDocument"); break;
                    default: return false;
                }
                return true;
            }); menu.show();
        });
        getOnBackPressedDispatcher().addCallback(this, new OnBackPressedCallback(true) {
            @Override public void handleOnBackPressed() {
                webView.evaluateJavascript("Boolean(window.dismissRestaurantPrinterSettings&&window.dismissRestaurantPrinterSettings())", result -> {
                    if ("true".equals(result)) return;
                    if (webView.canGoBack()) webView.goBack();
                    else new AlertDialog.Builder(MainActivity.this).setMessage("Close the restaurant app?").setNegativeButton("Stay", null).setPositiveButton("Close", (dialog, which) -> finish()).show();
                });
            }
        });
    }

    private boolean sameOrigin(String value) {
        try { URI current = new URI(value), server = new URI(serverUrl); return server.getScheme().equals(current.getScheme()) && server.getHost().equals(current.getHost()) && server.getPort() == current.getPort(); }
        catch (Exception error) { return false; }
    }

    private void printerAction(String method) {
        if (!sameOrigin(bridge.getWebView().getUrl())) return;
        bridge.getWebView().evaluateJavascript("(function(){if(typeof window." + method + "!=='function')return false;Promise.resolve(window." + method + "()).catch(function(){});return true;})()", result -> {
            if ("false".equals(result)) new AlertDialog.Builder(this).setTitle("Printing").setMessage("Upload the latest website build to enable native printer settings.").setPositiveButton("OK", null).show();
        });
    }

    private class PrintBridge {
        @JavascriptInterface public void print(int width, int height) { runOnUiThread(() -> { if (sameOrigin(bridge.getWebView().getUrl())) printPage(width, height); }); }
    }

    private void printPage(int width, int height) {
        PrintManager manager = (PrintManager) getSystemService(Context.PRINT_SERVICE);
        PrintAttributes.Builder attributes = new PrintAttributes.Builder();
        if (width >= 40 && width <= 120 && height >= 20 && height <= 5000) attributes.setMediaSize(new PrintAttributes.MediaSize("RECEIPT", "Receipt " + width + " mm", Math.round(width * 1000 / 25.4f), Math.round(height * 1000 / 25.4f))).setMinMargins(PrintAttributes.Margins.NO_MARGINS);
        PrintJob job = manager.print("Restaurant document", bridge.getWebView().createPrintDocumentAdapter("Restaurant document"), attributes.build());
        Handler handler = new Handler(Looper.getMainLooper());
        handler.postDelayed(new Runnable() {
            @Override public void run() {
                if (isFinishing()) return;
                if (job.isCancelled() || job.isCompleted() || job.isFailed()) bridge.getWebView().evaluateJavascript("window.dispatchEvent(new Event('afterprint'));", null);
                else handler.postDelayed(this, 1000);
            }
        }, 1000);
    }
}
