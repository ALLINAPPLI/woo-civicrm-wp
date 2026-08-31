/**
 * Formulaire admin commande : masque ND / Autre uniquement s’ils ne sont
 * pas déjà la valeur enregistrée (commandes historiques).
 * Le blocage completed reste côté PHP (required-payment-method.php).
 */
jQuery(function ($) {
  var $select = $("#_payment_method");
  if (!$select.length) {
    return;
  }

  var i18n = typeof wcCivicrmPaymentMethod !== "undefined" ? wcCivicrmPaymentMethod : {};
  var placeholder = i18n.placeholder || "Sélectionner un moyen de paiement";
  var ndLabel = i18n.ndLabel || "ND";
  var current = $select.val();

  function isNewOrder() {
    var original = $('input[name="original_post_status"]').val() || "";
    if (original === "auto-draft" || original === "draft") {
      return true;
    }
    var params = new URLSearchParams(window.location.search);
    if (params.get("action") === "new") {
      return true;
    }
    if (window.location.pathname.indexOf("post-new.php") !== -1) {
      return true;
    }
    var postId =
      $("#post_ID").val() ||
      $('input[name="id"]').val() ||
      params.get("id") ||
      params.get("post");
    return !postId || postId === "0";
  }

  // Nouvelle commande : placeholder, pas Autre. On garde value="" pour
  // forcer un choix explicite (le PHP refuse completed tant que c’est vide).
  if (isNewOrder()) {
    $select.find('option[value=""]').text(placeholder);
    $select.find('option[value="other"]').remove();
    return;
  }

  // Commande existante : ne jamais retirer l’option actuellement enregistrée,
  // sinon le select bascule sur la première gateway et écrase ND / Autre.
  if (current === "") {
    $select.find('option[value=""]').text(ndLabel);
  } else {
    $select.find('option[value=""]').remove();
  }

  if (current !== "other") {
    $select.find('option[value="other"]').remove();
  }
});
