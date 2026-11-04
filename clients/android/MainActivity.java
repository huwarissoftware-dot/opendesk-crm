package org.opendesk.crm;

import android.app.Activity;
import android.app.DownloadManager;
import android.content.ActivityNotFoundException;
import android.content.Context;
import android.content.Intent;
import android.content.SharedPreferences;
import android.graphics.Color;
import android.net.Uri;
import android.os.Build;
import android.os.Bundle;
import android.os.Environment;
import android.view.Menu;
import android.view.MenuItem;
import android.view.ViewGroup;
import android.webkit.CookieManager;
import android.webkit.DownloadListener;
import android.webkit.JavascriptInterface;
import android.webkit.URLUtil;
import android.webkit.ValueCallback;
import android.webkit.WebChromeClient;
import android.webkit.WebResourceError;
import android.webkit.WebResourceRequest;
import android.webkit.WebSettings;
import android.webkit.WebView;
import android.webkit.WebViewClient;
import android.widget.FrameLayout;
import android.widget.ProgressBar;
import android.widget.Toast;

/**
 * OpenDesk CRM - Android client.
 *
 * A thin WebView client for the OpenDesk CRM server application. All data
 * lives on the server; this app only stores the server address the user
 * entered on first launch (changeable later from the menu).
 */
public class MainActivity extends Activity {

    private static final String PREFS = "opendesk_client";
    private static final String KEY_SERVER = "server_url";
    private static final String SETUP_URL = "file:///android_asset/setup.html";
    private static final String ERROR_URL = "file:///android_asset/error.html";
    private static final int FILE_CHOOSER_REQUEST = 1001;

    private WebView webView;
    private ProgressBar progressBar;
    private ValueCallback<Uri[]> filePathCallback;

    // ------------------------------------------------------------------
    // Lifecycle
    // ------------------------------------------------------------------

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);

        FrameLayout root = new FrameLayout(this);
        webView = new WebView(this);
        root.addView(webView, new FrameLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.MATCH_PARENT));

        progressBar = new ProgressBar(this, null, android.R.attr.progressBarStyleHorizontal);
        progressBar.setMax(100);
        FrameLayout.LayoutParams pp = new FrameLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT, 8);
        root.addView(progressBar, pp);
        setContentView(root);

        configureWebView();

        String server = getServerUrl();
        if (server == null || server.length() == 0) {
            showSetup();
        } else {
            loadServer();
        }
    }

    @Override
    protected void onDestroy() {
        if (webView != null) {
            webView.destroy();
        }
        super.onDestroy();
    }

    @Override
    public void onBackPressed() {
        if (webView != null && webView.canGoBack()) {
            webView.goBack();
        } else {
            super.onBackPressed();
        }
    }

    // ------------------------------------------------------------------
    // Options menu: Change Server / Reload
    // ------------------------------------------------------------------

    @Override
    public boolean onCreateOptionsMenu(Menu menu) {
        menu.add(0, 1, 0, "Change Server");
        menu.add(0, 2, 1, "Reload");
        return true;
    }

    @Override
    public boolean onOptionsItemSelected(MenuItem item) {
        if (item.getItemId() == 1) {
            showSetup();
            return true;
        }
        if (item.getItemId() == 2) {
            String server = getServerUrl();
            if (server == null || server.length() == 0) {
                showSetup();
            } else if (isLocalPage(webView.getUrl())) {
                loadServer();
            } else {
                webView.reload();
            }
            return true;
        }
        return super.onOptionsItemSelected(item);
    }

    // ------------------------------------------------------------------
    // WebView configuration
    // ------------------------------------------------------------------

    private void configureWebView() {
        WebSettings s = webView.getSettings();
        s.setJavaScriptEnabled(true);
        s.setDomStorageEnabled(true);
        s.setDatabaseEnabled(true);
        s.setAllowContentAccess(true);
        s.setLoadsImagesAutomatically(true);
        s.setBuiltInZoomControls(false);
        s.setSupportZoom(false);
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.LOLLIPOP) {
            s.setMixedContentMode(WebSettings.MIXED_CONTENT_COMPATIBILITY_MODE);
        }

        CookieManager cookies = CookieManager.getInstance();
        cookies.setAcceptCookie(true);
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.LOLLIPOP) {
            cookies.setAcceptThirdPartyCookies(webView, true);
        }

        webView.setWebViewClient(new WebViewClient() {
            @Override
            public boolean shouldOverrideUrlLoading(WebView view, WebResourceRequest request) {
                Uri uri = request.getUrl();
                return handleNavigation(uri);
            }

            @Override
            @SuppressWarnings("deprecation")
            public boolean shouldOverrideUrlLoading(WebView view, String url) {
                return handleNavigation(Uri.parse(url));
            }

            @Override
            public void onReceivedError(WebView view, WebResourceRequest request,
                                        WebResourceError error) {
                if (request.isForMainFrame()) {
                    showError();
                }
            }

            @Override
            @SuppressWarnings("deprecation")
            public void onReceivedError(WebView view, int errorCode,
                                        String description, String failingUrl) {
                showError();
            }

            @Override
            public void onReceivedSslError(WebView view,
                                           android.webkit.SslErrorHandler handler,
                                           android.net.http.SslError error) {
                // Do not silently accept bad certificates - show the error page.
                handler.cancel();
                showError();
            }
        });

        webView.setWebChromeClient(new WebChromeClient() {
            @Override
            public void onProgressChanged(WebView view, int newProgress) {
                progressBar.setProgress(newProgress);
                progressBar.setVisibility(newProgress >= 100
                        ? android.view.View.GONE : android.view.View.VISIBLE);
            }

            @Override
            public boolean onShowFileChooser(WebView view,
                                             ValueCallback<Uri[]> filePathCallback,
                                             FileChooserParams fileChooserParams) {
                if (MainActivity.this.filePathCallback != null) {
                    MainActivity.this.filePathCallback.onReceiveValue(null);
                }
                MainActivity.this.filePathCallback = filePathCallback;
                Intent intent = null;
                try {
                    intent = fileChooserParams.createIntent();
                } catch (Exception ignored) {
                    intent = null;
                }
                if (intent == null) {
                    intent = new Intent(Intent.ACTION_OPEN_DOCUMENT);
                    intent.addCategory(Intent.CATEGORY_OPENABLE);
                    intent.setType("*/*");
                    String[] types = fileChooserParams.getAcceptTypes();
                    if (types != null && types.length > 0 && types[0] != null
                            && types[0].length() > 0) {
                        intent.setType(types[0]);
                    }
                }
                if (fileChooserParams.getMode() == FileChooserParams.MODE_OPEN_MULTIPLE) {
                    intent.putExtra(Intent.EXTRA_ALLOW_MULTIPLE, true);
                }
                try {
                    startActivityForResult(intent, FILE_CHOOSER_REQUEST);
                } catch (ActivityNotFoundException e) {
                    MainActivity.this.filePathCallback = null;
                    Toast.makeText(MainActivity.this,
                            "No app available to select a file.", Toast.LENGTH_LONG).show();
                    return false;
                }
                return true;
            }
        });

        webView.setDownloadListener(new DownloadListener() {
            @Override
            public void onDownloadStart(String url, String userAgent, String contentDisposition,
                                        String mimeType, long contentLength) {
                try {
                    DownloadManager.Request req =
                            new DownloadManager.Request(Uri.parse(url));
                    req.setMimeType(mimeType);
                    req.addRequestHeader("User-Agent", userAgent);
                    String cookie = CookieManager.getInstance().getCookie(url);
                    if (cookie != null) {
                        req.addRequestHeader("Cookie", cookie);
                    }
                    req.setTitle(URLUtil.guessFileName(url, contentDisposition, mimeType));
                    req.setDescription("Downloading file...");
                    req.setNotificationVisibility(
                            DownloadManager.Request.VISIBILITY_VISIBLE_NOTIFY_COMPLETED);
                    req.setDestinationInExternalPublicDir(Environment.DIRECTORY_DOWNLOADS,
                            URLUtil.guessFileName(url, contentDisposition, mimeType));
                    DownloadManager dm = (DownloadManager) getSystemService(
                            Context.DOWNLOAD_SERVICE);
                    dm.enqueue(req);
                    Toast.makeText(MainActivity.this, "Downloading file...",
                            Toast.LENGTH_SHORT).show();
                } catch (Exception e) {
                    // Fall back to opening the link in the browser.
                    try {
                        startActivity(new Intent(Intent.ACTION_VIEW, Uri.parse(url)));
                    } catch (Exception ignored) {
                    }
                }
            }
        });
    }

    /**
     * Returns true when the navigation was handled outside the WebView
     * (external app / browser), false to let the WebView load it.
     */
    private boolean handleNavigation(Uri uri) {
        String scheme = uri.getScheme();
        if (scheme == null) return false;
        if (scheme.equals("tel") || scheme.equals("mailto") || scheme.equals("sms")
                || scheme.equals("geo") || scheme.equals("intent")) {
            try {
                startActivity(new Intent(Intent.ACTION_VIEW, uri));
            } catch (Exception ignored) {
            }
            return true;
        }
        if (scheme.equals("http") || scheme.equals("https")) {
            String server = getServerUrl();
            String serverHost = null;
            if (server != null) {
                serverHost = Uri.parse(server).getHost();
            }
            String host = uri.getHost();
            if (host != null && serverHost != null && host.equalsIgnoreCase(serverHost)) {
                return false; // same server: stay inside the app
            }
            if (isLocalPage(webView.getUrl())) {
                return false; // local setup/error pages do not link out
            }
            // External link: open in the device browser.
            try {
                startActivity(new Intent(Intent.ACTION_VIEW, uri));
            } catch (Exception ignored) {
            }
            return true;
        }
        return false;
    }

    // ------------------------------------------------------------------
    // Page loading helpers
    // ------------------------------------------------------------------

    private boolean isLocalPage(String url) {
        return url != null && url.startsWith("file:///android_asset/");
    }

    private String getServerUrl() {
        SharedPreferences p = getSharedPreferences(PREFS, Context.MODE_PRIVATE);
        return p.getString(KEY_SERVER, "");
    }

    private void saveServerUrl(String url) {
        getSharedPreferences(PREFS, Context.MODE_PRIVATE)
                .edit().putString(KEY_SERVER, url).apply();
    }

    private void showSetup() {
        webView.removeJavascriptInterface("OpenDeskBridge");
        webView.addJavascriptInterface(new ClientBridge(), "OpenDeskBridge");
        webView.loadUrl(SETUP_URL);
    }

    private void showError() {
        webView.removeJavascriptInterface("OpenDeskBridge");
        webView.addJavascriptInterface(new ClientBridge(), "OpenDeskBridge");
        webView.loadUrl(ERROR_URL);
    }

    private void loadServer() {
        String server = getServerUrl();
        if (server == null || server.length() == 0) {
            showSetup();
            return;
        }
        // The bridge is only exposed to the app's own local pages.
        webView.removeJavascriptInterface("OpenDeskBridge");
        webView.loadUrl(server);
    }

    static String normalizeServerUrl(String raw) {
        if (raw == null) return "";
        String v = raw.trim();
        if (v.length() == 0) return "";
        if (!v.startsWith("http://") && !v.startsWith("https://")) {
            v = "http://" + v;
        }
        return v;
    }

    // ------------------------------------------------------------------
    // File chooser result
    // ------------------------------------------------------------------

    @Override
    protected void onActivityResult(int requestCode, int resultCode, Intent data) {
        super.onActivityResult(requestCode, resultCode, data);
        if (requestCode == FILE_CHOOSER_REQUEST) {
            if (filePathCallback == null) return;
            Uri[] results = null;
            if (resultCode == RESULT_OK && data != null) {
                if (data.getClipData() != null) {
                    int count = data.getClipData().getItemCount();
                    results = new Uri[count];
                    for (int i = 0; i < count; i++) {
                        results[i] = data.getClipData().getItemAt(i).getUri();
                    }
                } else if (data.getData() != null) {
                    results = new Uri[]{data.getData()};
                }
            }
            filePathCallback.onReceiveValue(results);
            filePathCallback = null;
        }
    }

    // ------------------------------------------------------------------
    // JavaScript bridge - exposed ONLY to the local setup/error pages
    // ------------------------------------------------------------------

    public class ClientBridge {
        @JavascriptInterface
        public void saveServer(final String url) {
            final String normalized = normalizeServerUrl(url);
            if (normalized.length() == 0) return;
            saveServerUrl(normalized);
            runOnUiThread(new Runnable() {
                @Override
                public void run() {
                    loadServer();
                }
            });
        }

        @JavascriptInterface
        public String getServer() {
            return getServerUrl();
        }

        @JavascriptInterface
        public void retry() {
            runOnUiThread(new Runnable() {
                @Override
                public void run() {
                    loadServer();
                }
            });
        }

        @JavascriptInterface
        public void changeServer() {
            runOnUiThread(new Runnable() {
                @Override
                public void run() {
                    showSetup();
                }
            });
        }
    }
}
