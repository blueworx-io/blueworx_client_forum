<?php
/**
 * The Kinetic page as shipped.
 *
 * Copy is from the Figma design. The design only writes out the first FAQ
 * answer; the other five are drafted from the page's own sections. Media
 * fields hold a filename under assets/seed/ — the seeder swaps each for the
 * attachment it makes.
 *
 * @package ExternalProductImages
 */

defined( 'ABSPATH' ) || exit;

return array(
	'title'  => 'Getting started with kinetic wireless switches',
	'slug'   => 'getting-started-with-kinetic-wireless-switches',
	'values' => array(
		'hero_eyebrow'             => 'KINETIC',
		'hero_heading'             => 'Getting started with kinetic wireless switches',
		'hero_breadcrumb'          => 'Kinetic wireless switches',
		'hero_intro'               => 'A switch that powers itself from the press, sends the command by radio and needs no cable back to the circuit. Here is what that means on site, where it earns its place, and what to specify.',
		'hero_image'               => 'kinetic-hero.jpg',
		'hero_cta1_label'          => 'View the Kinetic range',
		'hero_cta1_url'            => '/product-category/kinetic/',
		'hero_cta2_label'          => 'Talk to a Technical Specialist',
		'hero_cta2_url'            => '/contact/',
		'hero_meta_category'       => 'LIGHTING CONTROLS',
		'hero_meta_read'           => '6 MIN READ',
		'hero_meta_updated'        => 'UPDATED JULY 2026',

		'sectionbar_label'         => 'ON THIS PAGE',
		'sectionbar_phone'         => '0161 359 4949',

		'what_nav_label'           => 'What they are',
		'what_heading'             => 'A switch with no supply and no battery',
		'what_body'                => '<p>A kinetic switch generates its own power. Pressing the rocker moves a small generator inside the plate, and that movement produces enough energy to send a single radio command — nothing else is needed behind it.</p><p>Because there is no supply at the switch, there is no cable to run and no back box to cut in. The switching itself happens at a paired receiver on the lighting circuit, at the fitting or in the ceiling void.</p>',
		'what_pullquote'           => 'The switch position is no longer decided by the wiring. It is decided by where the client wants to reach.',
		'what_parts'               => array(
			array(
				'title' => 'Rocker',
				'desc'  => 'Press supplies the energy',
			),
			array(
				'title' => 'Generator',
				'desc'  => 'Induction coil, no battery',
			),
			array(
				'title' => 'Transmitter',
				'desc'  => 'Sends the paired command',
			),
		),

		'how_nav_label'            => 'How they work',
		'how_heading'              => 'Press, generate, transmit, switch',
		'how_intro'                => 'Four things happen in the time it takes to click the rocker. Every kinetic installation is a version of this sequence.',
		'how_steps'                => array(
			array(
				'title' => 'Press',
				'body'  => 'The rocker moves a lever inside the plate. That movement is the only energy the switch ever needs.',
				'image' => 0,
			),
			array(
				'title' => 'Generate',
				'body'  => 'The movement drives a miniature generator, producing a brief pulse of power — no battery, no supply.',
				'image' => 0,
			),
			array(
				'title' => 'Transmit',
				'body'  => 'The pulse powers a short radio signal carrying the command and the switch identity.',
				'image' => 0,
			),
			array(
				'title' => 'Switch',
				'body'  => 'The paired receiver on the lighting circuit reads the command and switches or dims the load.',
				'image' => 0,
			),
		),

		'advantages_nav_label'     => 'Advantages',
		'advantages_heading'       => 'What you gain, and what to allow for',
		'advantages_wins_label'    => 'WHERE KINETIC WINS',
		'advantages_wins'          => array(
			array( 'item' => 'No cable back to the switch position — nothing to chase into a finished wall' ),
			array( 'item' => 'No batteries to replace over the life of the fitting' ),
			array( 'item' => 'Switch plates can be sited on glass, tile, brick or plasterboard' ),
			array( 'item' => 'Add two-way and three-way control after first fix without rewiring' ),
			array( 'item' => 'One switch can be paired to several receivers, or several to one' ),
		),
		'advantages_allow_label'   => 'WHAT TO ALLOW FOR',
		'advantages_allow'         => array(
			array( 'item' => 'Every switched circuit needs a paired receiver at the fitting or in the ceiling void' ),
			array( 'item' => 'Radio range is affected by the building fabric — check dense walls and metal on site' ),
			array( 'item' => 'Existing wired switches are not reused; they are replaced or made off' ),
			array( 'item' => 'Pairing is a commissioning step and should be recorded for the handover' ),
		),

		'where_nav_label'          => 'Where they work',
		'where_heading'            => 'Jobs where the cable is the problem',
		'where_intro'              => 'Kinetic switching pays for itself wherever a new switch drop would mean damage, delay or a second visit.',
		'where_cards'              => array(
			array(
				'image' => 0,
				'title' => 'Retrofit and refurbishment',
				'body'  => 'Finished plaster, tiled walls and listed interiors where chasing a new switch drop is not an option.',
			),
			array(
				'image' => 0,
				'title' => 'Kitchens and utility',
				'body'  => 'Switching at the worktop, island or pantry after the units are set out.',
			),
			array(
				'image' => 0,
				'title' => 'Bathrooms',
				'body'  => 'Plate sited outside the zones, receiver at the fitting.',
			),
			array(
				'image' => 0,
				'title' => 'Glass and partitions',
				'body'  => 'Bonded to glass balustrades, stud partitions and demountable office walls.',
			),
			array(
				'image' => 0,
				'title' => 'Outbuildings and garden rooms',
				'body'  => 'Local switching without a second cable run from the house.',
			),
			array(
				'image' => 0,
				'title' => 'Landlord and HMO work',
				'body'  => 'Adding two-way control to stairs and landings between tenancies.',
			),
		),

		'comparison_nav_label'     => 'Compare',
		'comparison_heading'       => 'Kinetic, battery RF and wired switching',
		'comparison_col1'          => 'KINETIC',
		'comparison_col2'          => 'BATTERY RF',
		'comparison_col3'          => 'WIRED',
		'comparison_rows'          => array(
			array(
				'label' => 'Power at the switch',
				'col1'  => 'Harvested from the press',
				'col2'  => 'Battery cell',
				'col3'  => 'Mains cable',
			),
			array(
				'label' => 'Cable to switch position',
				'col1'  => 'None',
				'col2'  => 'None',
				'col3'  => 'Required',
			),
			array(
				'label' => 'Consumables',
				'col1'  => 'None',
				'col2'  => 'Battery replacement',
				'col3'  => 'None',
			),
			array(
				'label' => 'Receiver required',
				'col1'  => 'Yes',
				'col2'  => 'Yes',
				'col3'  => 'No',
			),
			array(
				'label' => 'Best suited to',
				'col1'  => 'Retrofit and late changes',
				'col2'  => 'Low-use positions',
				'col3'  => 'New build first fix',
			),
		),

		'specifying_nav_label'     => 'Specifying',
		'specifying_heading'       => 'Six things to settle on site',
		'specifying_points'        => array(
			array(
				'title' => 'Count the circuits, not the switches',
				'body'  => 'A receiver is needed per switched load. Two plates onto one circuit still need only one receiver.',
			),
			array(
				'title' => 'Site the receiver where you can reach it',
				'body'  => 'Ceiling void, luminaire back box or above an access panel — pairing may need a second visit.',
			),
			array(
				'title' => 'Walk the range on site',
				'body'  => 'Test from the intended plate position before you fix it, with doors closed and the building as built.',
			),
			array(
				'title' => 'Check the load is compatible',
				'body'  => 'Confirm dimmable drivers and lamp types against the receiver before you order.',
			),
			array(
				'title' => 'Record the pairing',
				'body'  => 'Note plate against circuit on the as-built so the next visit is not a guessing game.',
			),
			array(
				'title' => 'Leave the client a spare plate position',
				'body'  => 'Kinetic makes later additions easy; agree where a second control might go.',
			),
		),
		'specifying_trouble_label' => 'IF SOMETHING IS NOT WORKING',
		'specifying_trouble'       => array(
			array(
				'title' => 'The receiver does not respond',
				'body'  => 'Re-run the pairing sequence with the plate held at the fitting, then re-test from the wall position. Working close-to but not at the wall is a range problem, not a fault.',
			),
			array(
				'title' => 'One of two gangs works',
				'body'  => 'Each gang pairs separately. Confirm the second gang has been paired to its own receiver.',
			),
			array(
				'title' => 'Dimming is uneven or flickers',
				'body'  => 'Check the driver or lamp is dimmable and matched to the receiver output. Mixed loads on one circuit are the usual cause.',
			),
		),

		'faqs_nav_label'           => 'FAQs',
		'faqs_heading'             => 'Frequently asked',
		'faqs_items'               => array(
			array(
				'question' => 'Do kinetic switches need a battery?',
				'answer'   => 'No. The energy comes from the press itself, so there is nothing to charge and nothing to replace.',
			),
			array(
				'question' => 'Can I use a kinetic switch with existing wiring?',
				'answer'   => 'Yes. The receiver fits at the fitting or in the ceiling void on the existing lighting circuit. The old wired switch is replaced or made off; it is not reused.',
			),
			array(
				'question' => 'How many switches can control one light?',
				'answer'   => 'Several plates can be paired to one receiver, and one plate to several receivers, so two-way and three-way control is a pairing step rather than a wiring job.',
			),
			array(
				'question' => 'Will it work through a wall?',
				'answer'   => 'Usually. Range depends on the building fabric — dense masonry and metal reduce it — so test from the intended plate position before fixing.',
			),
			array(
				'question' => 'Are they suitable for bathrooms?',
				'answer'   => 'Yes, with the plate sited outside the zones and the receiver at the fitting.',
			),
			array(
				'question' => 'Can they be surface fixed to glass or tile?',
				'answer'   => 'Yes. With no back box to cut in, plates can be bonded to glass, tile, brick or plasterboard.',
			),
		),

		'cta_eyebrow'              => 'NEXT STEP',
		'cta_heading'              => 'Send us the drawing and we will mark up the controls',
		'cta_body'                 => 'Our in-house Technical Specialists will confirm receivers against the circuits, check load compatibility and quote the plates. Orders placed before 2pm go out on DPD next day delivery.',
		'cta_cta1_label'           => 'Talk to a Technical Specialist',
		'cta_cta1_url'             => '/contact/',
		'cta_cta2_label'           => 'Download the catalogue',
		'cta_cta2_url'             => '/catalogues/',
		'cta_stats'                => array(
			array(
				'value' => '60+',
				'label' => 'YEARS TRADING',
			),
			array(
				'value' => 'Next day',
				'label' => 'DPD BEFORE 2PM',
			),
			array(
				'value' => 'ISO 9001',
				'label' => '& ISO 14001',
			),
		),
	),
);
