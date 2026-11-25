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
import android.widget.EditText;
import android.widget.PopupMenu;
import androidx.activity.OnBackPressedCallback;
import com.getcapacitor.BridgeActivity;
import com.getcapacitor.BridgeWebViewClient;
import com.getcapacitor.CapConfig;
import java.net.URI;

public class MainActivity extends BridgeActivity {
    private static final String PRODUCTION_URL = "https://restaurant.mkposmyanmar.com";
    private String serverUrl;
    private boolean showingError;

    @Override
    public void onCreate(Bundle savedInstanceState) {
        serverUrl = getPreferences(MODE_PRIVATE).getString("serverUrl", PRODUCTION_URL);
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
                if (sameOrigin(url)) view.evaluateJavascript("window.print=function(){var p=document.getElementById('printable-receipt');RestaurantNative.print(p?Number(p.dataset.paperWidth):0,p?Math.ceil(p.scrollHeight*25.4/96)+4:0);};", null);
            }
            @Override
            public void onReceivedError(WebView view, WebResourceRequest request, WebResourceError error) {
                super.onReceivedError(view, request, error);
                if (request.isForMainFrame() && !showingError && !isFinishing()) {
                    showingError = true;
                    new AlertDialog.Builder(MainActivity.this).setTitle("Cannot reach restaurant")
                        .setMessage("Check your connection and server address.\n" + serverUrl)
                        .setPositiveButton("Retry", (dialog, which) -> { showingError = false; view.loadUrl(serverUrl); })
                        .setNegativeButton("Server settings", (dialog, which) -> { showingError = false; showServerSettings(); })
                        .setOnCancelListener(dialog -> showingError = false).show();
                }
            }
        });
        findViewById(R.id.restaurant_menu).setOnClickListener(view -> {
            PopupMenu menu = new PopupMenu(this, view);
            menu.getMenu().add("Home"); menu.getMenu().add("Reload"); menu.getMenu().add("Print"); menu.getMenu().add("Server settings");
            menu.setOnMenuItemClickListener(item -> {
                switch (item.getTitle().toString()) {
                    case "Home": webView.loadUrl(serverUrl); break;
                    case "Reload": webView.reload(); break;
                    case "Print": printPage(0, 0); break;
                    default: showServerSettings(); break;
                }
                return true;
            }); menu.show();
        });
        getOnBackPressedDispatcher().addCallback(this, new OnBackPressedCallback(true) {
            @Override public void handleOnBackPressed() {
                if (webView.canGoBack()) webView.goBack();
                else new AlertDialog.Builder(MainActivity.this).setMessage("Close the restaurant app?").setNegativeButton("Stay", null).setPositiveButton("Close", (dialog, which) -> finish()).show();
            }
        });
    }

    private boolean sameOrigin(String value) {
        try { URI current = new URI(value), server = new URI(serverUrl); return server.getScheme().equals(current.getScheme()) && server.getHost().equals(current.getHost()) && server.getPort() == current.getPort(); }
        catch (Exception error) { return false; }
    }

    private void showServerSettings() {
        EditText address = new EditText(this); address.setSingleLine(true); address.setText(serverUrl);
        address.setInputType(android.text.InputType.TYPE_CLASS_TEXT | android.text.InputType.TYPE_TEXT_VARIATION_URI);
        AlertDialog dialog = new AlertDialog.Builder(this).setTitle("Restaurant server").setView(address)
            .setMessage("Use HTTPS for camera access. Localhost refers to this Android device.")
            .setNegativeButton("Cancel", null).setNeutralButton("Production", (d, w) -> saveServer(PRODUCTION_URL)).setPositiveButton("Connect", null).create();
        dialog.setOnShowListener(ignored -> dialog.getButton(AlertDialog.BUTTON_POSITIVE).setOnClickListener(view -> {
            try {
                URI uri = new URI(address.getText().toString().trim());
                if (!("https".equals(uri.getScheme()) || "http".equals(uri.getScheme())) || uri.getHost() == null || uri.getUserInfo() != null || uri.getQuery() != null || uri.getFragment() != null || !(uri.getPath().isEmpty() || uri.getPath().equals("/"))) throw new IllegalArgumentException();
                saveServer(uri.getScheme() + "://" + uri.getRawAuthority()); dialog.dismiss();
            } catch (Exception error) { address.setError("Enter a server address such as https://pos.example.com"); }
        })); dialog.show();
    }

    private void saveServer(String value) {
        getPreferences(MODE_PRIVATE).edit().putString("serverUrl", value).apply(); recreate();
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
