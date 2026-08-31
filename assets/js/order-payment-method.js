/**
 * Formulaire admin commande : clarifie ND et retire Autre s’il n’est pas déjà choisi.
 * Le blocage completed reste côté PHP (required-payment-method.php).
 */
jQuery(function ($) {
  var $select = $("#_payment_method");
  if (!$select.length) {
    return;
  }

  var placeholder =
    (typeof wcCivicrmPaymentMethod !== "undefined" &&
      wcCivicrmPaymentMethod.placeholder) ||
    "Sélectionner un moyen de paiement";

  $select.find('option[value=""]').text(placeholder);

  $select.find('option[value="other"]').each(function () {
    if (!this.selected) {
      $(this).remove();
    }
  });
});
