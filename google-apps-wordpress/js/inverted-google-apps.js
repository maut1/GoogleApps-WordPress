$ = jQuery.noConflict();

$(document).ready(function()
									{
											$('#media-items.gdocs .insert-as input[type=radio]').click(
													function()
													{
															var form = $(this).closest('form');
															var value = $(this).val();

															if($(this).is(':checked'))
															  $(form).find('.insert-type:visible').fadeOut(
																		100,
																		function()
																		{
																				$(form).find('.' + value).fadeIn(100);
																		});
													});

											$(document).on('click', '#media-items.gdocs .publish-toggle input[type=button]',
													function()
													{
															var form = $(this).closest('form');
															var resource_id = $(form).find('input[name=resource_id]').val();
															var button = this;

															$(button).closest('.publish-toggle').children('.loading').show();

															$.post(ajaxurl,
																		 {
																				 action: 'gdocs_publish_toggle',
																				 status: $(this).data('status'),
																				 resource_id: resource_id
																		 },
																		 function(response)
																		 {
																				 $(button).closest('.publish-toggle').children('.loading').hide();

																				 if(response.error)
																						 $('.error').html(response.error)
																				 else if(response.html)
																						 $(button).closest('.action').html(response.html);
																		 },
																		'json');
													});

											$('.media-item .docs-icon, .media-item .filename').click(
													function() 
													{
															$(this).parent().children('.insert-options').slideToggle(200); 
													});
									});