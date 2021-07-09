<?php

/* @admin/login_frame.twig */
class __TwigTemplate_702927c37b1865edbebdc0e43bf3710b0e483a1881cdef6894c21de4e676ccc1 extends Twig_Template
{
    private $source;

    public function __construct(Twig_Environment $env)
    {
        parent::__construct($env);

        $this->source = $this->getSourceContext();

        $this->parent = false;

        $this->blocks = [
            'main' => [$this, 'block_main'],
            'javascript' => [$this, 'block_javascript'],
        ];
    }

    protected function doDisplay(array $context, array $blocks = [])
    {
        // line 1
        echo "<!doctype html>
";
        // line 12
        echo "<html lang=\"ja\">
<head>
    <meta charset=\"utf-8\">
    <meta name=\"viewport\" content=\"width=device-width, initial-scale=1, shrink-to-fit=no\">
    <title>";
        // line 16
        echo twig_escape_filter($this->env, $this->extensions['Symfony\Bridge\Twig\Extension\TranslationExtension']->trans("admin.login"), "html", null, true);
        echo " - ";
        echo twig_escape_filter($this->env, twig_get_attribute($this->env, $this->source, ($context["BaseInfo"] ?? null), "shop_name", []), "html", null, true);
        echo "</title>
    <meta name=\"description\" content=\"\">
    <meta name=\"author\" content=\"\">
    <meta name=\"robots\" content=\"noindex,nofollow\"/>
    <link rel=\"icon\" href=\"";
        // line 20
        echo twig_escape_filter($this->env, $this->extensions['Symfony\Bridge\Twig\Extension\AssetExtension']->getAssetUrl("assets/img/favicon.ico", "admin"), "html", null, true);
        echo "\">
    <link rel=\"stylesheet\" href=\"";
        // line 21
        echo twig_escape_filter($this->env, $this->extensions['Symfony\Bridge\Twig\Extension\AssetExtension']->getAssetUrl("assets/css/bootstrap.css", "admin"), "html", null, true);
        echo "\">
    <script src=\"https://code.jquery.com/jquery-3.3.1.min.js\" integrity=\"sha256-FgpCb/KJQlLNfOu91ta32o/NMZxltwRo8QtmkMRdAu8=\" crossorigin=\"anonymous\"></script>
</head>
<body id=\"login-page\" class=\"bg-light\">
<noscript>
    <p>";
        // line 26
        echo twig_escape_filter($this->env, $this->extensions['Symfony\Bridge\Twig\Extension\TranslationExtension']->trans("admin.login.enable_javascript"), "html", null, true);
        echo "</p>
</noscript>

<div class=\"c-container\">
    <div class=\"c-contentsArea\">
        ";
        // line 31
        echo twig_include($this->env, $context, "@admin/alert.twig");
        echo "

        ";
        // line 33
        $this->displayBlock('main', $context, $blocks);
        // line 34
        echo "
    </div>
</div>

<script src=\"https://cdnjs.cloudflare.com/ajax/libs/popper.js/1.14.3/umd/popper.min.js\" integrity=\"sha384-ZMP7rVo3mIykV+2+9J3UJ46jBk0WLaUAdn689aCwoqbBJiSnjAK/l8WvCWPIPm49\" crossorigin=\"anonymous\"></script>
<script src=\"https://stackpath.bootstrapcdn.com/bootstrap/4.1.3/js/bootstrap.min.js\" integrity=\"sha384-ChfqqxuZUCnJSK3+MXmPNIyE6ZbWh2IMqE241rYiqJxyMiZ6OW/JmZQ5stwEULTy\" crossorigin=\"anonymous\"></script>
<script src=\"";
        // line 40
        echo twig_escape_filter($this->env, $this->extensions['Symfony\Bridge\Twig\Extension\AssetExtension']->getAssetUrl("assets/js/function.js", "admin"), "html", null, true);
        echo "\"></script>
";
        // line 41
        $this->displayBlock('javascript', $context, $blocks);
        // line 42
        echo "</body>
</html>
";
    }

    // line 33
    public function block_main($context, array $blocks = [])
    {
    }

    // line 41
    public function block_javascript($context, array $blocks = [])
    {
    }

    public function getTemplateName()
    {
        return "@admin/login_frame.twig";
    }

    public function isTraitable()
    {
        return false;
    }

    public function getDebugInfo()
    {
        return array (  95 => 41,  90 => 33,  84 => 42,  82 => 41,  78 => 40,  70 => 34,  68 => 33,  63 => 31,  55 => 26,  47 => 21,  43 => 20,  34 => 16,  28 => 12,  25 => 1,);
    }

    public function getSourceContext()
    {
        return new Twig_Source("", "@admin/login_frame.twig", "C:\\xampp\\htdocs\\ec-cube\\src\\Eccube\\Resource\\template\\admin\\login_frame.twig");
    }
}
