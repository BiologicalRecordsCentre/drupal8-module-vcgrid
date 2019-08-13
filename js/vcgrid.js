(function($) {
  Drupal.behaviors.vcgrid = {
    attach: function(context, settings) {

      // Find all the image map areas (once i.e. on first page load only)
        // Attach a click handler to the map areas
          $('area').once().click(function(event){
            event.preventDefault();
          // Get the vice county name from the alt attribute of the area
          var viceCounty = $(this).attr('alt');
          // Find the option in the select whose text matches the vice county
		  var $select = $('#edit-vice-county');
          var $option = $('#edit-vice-county option').filter(function() {
            return($(this).text() == viceCounty);
          })
          // Set the option to match the area clicked
          // prop() function introduced in jQuery 1.6 and
          // attr() function deprecated.
          var iCanUseProp = !!$.fn.prop;
          if(iCanUseProp) {
			$select.val($option[0].value);                                //add new options
			$select.trigger('chosen:updated');			
          }
          else {
            $option.attr('selected', true);
          }
      })
    }
  }
})(jQuery);