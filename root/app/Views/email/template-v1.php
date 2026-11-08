<!DOCTYPE html>
<html lang="{{lang}}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="color-scheme" content="light">
    <meta name="supported-color-schemes" content="light">
    <title>{{subject}}</title>
    <meta name="color-scheme" content="light dark">
    <meta name="supported-color-schemes" content="light dark">
    <style type="text/css">
        /* Light mode defaults */
        body,
        .email-body {
            background-color: #F5F6F8 !important;
            color: #444444 !important;
        }

        .email-container {
            background-color: #FFFFFF !important;
        }

        .email-heading {
            color: #171717 !important;
        }

        .email-text {
            color: #444444 !important;
        }

        .email-footer {
            background-color: #FAFAFA !important;
            color: #888888 !important;
        }

        .email-divider {
            border-color: #EEEEEE !important;
        }

        .email-button {
            background-color: #171717 !important;
            color: #FFFFFF !important;
        }

        a, h1 {
            color: #c97232 !important;
        }

        /* Dark mode */
        @media (prefers-color-scheme: dark) {
            body,
            .email-body {
                background-color: #121212 !important;
                color: #D1D1D1 !important;
            }

            .email-container {
                background-color: #1E1E1E !important;
            }

            .email-heading {
                color: #F5F5F5 !important;
            }

            .email-text {
                color: #D1D1D1 !important;
            }

            .email-footer {
                background-color: #181818 !important;
                color: #AAAAAA !important;
            }

            .email-divider {
                border-color: #333333 !important;
            }

            .email-button {
                background-color: #F5F5F5 !important;
                color: #171717 !important;
            }
        }

        /* Outlook.com dark mode */
        [data-ogsc] .email-container {
            background-color: #1E1E1E !important;
        }

        [data-ogsc] .email-heading {
            color: #F5F5F5 !important;
        }

        [data-ogsc] .email-text {
            color: #D1D1D1 !important;
        }
    </style>

</head>
<body style="
    margin: 0;
    padding: 0;
    background-color: #f5f6f8;
    font-family: -apple-system, BlinkMacSystemFont,
        'Segoe UI', Arial, sans-serif;
    color: #242424;
">

<table role="presentation" class="email-container"
       bgcolor="#FFFFFF"
       style="background-color:#FFFFFF; width: 100%">
    <tr>
        <td align="center" style="padding: 40px 16px;">
            <!-- Email Container -->
            <table role="presentation" width="100%" cellpadding="0"
                   cellspacing="0" border="0"
                   style="
               max-width: 600px;
               background-color: #ffffff;
               border-radius: 8px;
               overflow: hidden;
           ">
                <!-- Header -->
                <tr>
                    <td style="padding: 32px 40px 24px;">
                        <a href="https://otternova.com"
                           style="text-decoration: none;">
                            <img src="https://otternova.com/assets/img/logo-dark.webp"
                                 alt="OtterNova"
                                 height="32"
                                 style="
                             height: 32px;
                             width: auto;
                             margin-right: 15px;
                             border: 0;">
                            <h1 style="display:inline-block;margin:0">OtterNova</h1>
                        </a>
                    </td>
                </tr>
                <!-- Divider -->
                <tr>
                    <td style="padding: 0 40px;">
                        <div style="
                    height: 1px;
                    background-color: #eeeeee;
                    font-size: 1px;
                    line-height: 1px;
                ">&nbsp;</div>
                    </td>
                </tr>
                <!-- Main Content -->
                <tr>
                    <td style="
                padding: 36px 40px 40px;
                font-size: 15px;
                line-height: 1.7;
                color: #444444;
            ">

                        {{email_content}}

                        <p style="margin: 0 0 8px;">
                            {{closing_message}}
                        </p>
                        <p style="margin: 0; color: #242424;">
                            {{best_regards}}<br>
                            <strong>{{team_name}}</strong>
                        </p>

                    </td>
                </tr>
                <!-- Footer -->
                <tr>
                    <td style="
                padding: 28px 40px;
                background-color: #fafafa;
                border-top: 1px solid #eeeeee;
                font-size: 12px;
                line-height: 1.8;
                color: #888888;
            ">

                        <strong style="color: #555555;">
                            {{company_name}}
                        </strong><br>

                        {{company_address_line}}

                        <a href="https://otternova.com"
                           style="color: #666666;">
                            https://otternova.com
                        </a>
                        &nbsp;·&nbsp;
                        <a href="mailto:support@otternova.com"
                           style="color: #666666;">
                            {{contact_support}}
                        </a>
                        &nbsp;·&nbsp;
                        <a href="https://otternova.com/privacy-policy"
                           style="color: #666666;">
                            {{privacy_policy}}
                        </a>
                        <p style="
                    margin: 20px 0 0;
                    color: #aaaaaa;
                ">
                            © 2025-{{year}} OtterNova.
                            {{all_rights_reserved}}
                        </p>

                    </td>
                </tr>
            </table>
            <!-- End Email Container -->
        </td>
    </tr>
</table>
</body>
</html>