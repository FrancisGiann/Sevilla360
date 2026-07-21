document.addEventListener("DOMContentLoaded", () => {

  // 1. Target the big box you built in your HTML
const container = document.getElementById('pano-container');

// 2. Initialize the Panolens Viewer
const viewer = new PANOLENS.Viewer({ 
    container: container,
    controlBar: false // We set this to false because you built your own custom UI buttons!
});

// 3. Load the Photosphere JPG (We will fetch this path from your database later)
const eventHallPano = new PANOLENS.ImagePanorama('assets/uploads/your_photosphere_image.jpg');

// 4. Add it to the viewer
viewer.add(eventHallPano);

  // Room Data matching your Figma table
  const roomData = {
    infinity: {
      title: "INFINITY HALL",
      galleryTitle: "Event Hall",
      capacity: "Up to 1000 pax",
      ideal: "Perfect for weddings",
      tech: "Equipped with a built-in sound system",
      inc: "Fully air-conditioned hall",
      status: "Available",
      rate: "₱10,000 /day",
    },
    villa: {
      title: "LUXURY VILLA",
      galleryTitle: "Luxury Villa",
      capacity: "Up to 15 pax",
      ideal: "Private parties, family reunions",
      tech: "Smart TV, Premium WiFi, Bluetooth Audio",
      inc: "Private pool, kitchen, 3 bedrooms",
      status: "Limited Availability",
      rate: "₱25,000 /night",
    },
    standard: {
      title: "STANDARD ROOM",
      galleryTitle: "Standard Room",
      capacity: "2 pax",
      ideal: "Couples, short stays",
      tech: "Smart TV, WiFi",
      inc: "Queen bed, en-suite bathroom",
      status: "Available",
      rate: "₱3,500 /night",
    },
    deluxe: {
      title: "DELUXE ROOM",
      galleryTitle: "Deluxe Room",
      capacity: "2-4 pax",
      ideal: "Small families",
      tech: "Smart TV, WiFi, Mini-fridge",
      inc: "King bed, balcony, bathtub",
      status: "Available",
      rate: "₱5,500 /night",
    },
    family: {
      title: "FAMILY ROOM",
      galleryTitle: "Family Room",
      capacity: "Up to 6 pax",
      ideal: "Large families, groups",
      tech: "2 Smart TVs, Gaming Console, WiFi",
      inc: "2 Queen beds, living area",
      status: "Available",
      rate: "₱8,000 /night",
    },
  };

  /* ==============================================
       1. Table & Gallery Title Updates on Pill Click
       ============================================== */
  const pills = document.querySelectorAll(".pill");

  // UI Elements
  const valTitle = document.getElementById("val-title");
  const valCapacity = document.getElementById("val-capacity");
  const valIdeal = document.getElementById("val-ideal");
  const valTech = document.getElementById("val-tech");
  const valInc = document.getElementById("val-inc");
  const valStatus = document.getElementById("val-status");
  const valRate = document.getElementById("val-rate");
  const galleryTitle = document.getElementById("gallery-title");

  pills.forEach((pill) => {
    pill.addEventListener("click", function () {
      // Remove active class from all
      pills.forEach((p) => p.classList.remove("active"));
      // Add active class to clicked
      this.classList.add("active");

      // Get data
      const roomKey = this.getAttribute("data-room");
      const data = roomData[roomKey];

      // Update details
      valTitle.textContent = data.title;
      valCapacity.textContent = data.capacity;
      valIdeal.textContent = data.ideal;
      valTech.textContent = data.tech;
      valInc.textContent = data.inc;
      valStatus.textContent = data.status;
      valRate.textContent = data.rate;

      // Update photo gallery title
      galleryTitle.textContent = data.galleryTitle;
    });
  });

  /* ==============================================
       2. View Swap Logic (Full Screen Toggle)
       ============================================== */
  const btnViewPhotos = document.getElementById("btn-view-photos");
  const btnBackTo360 = document.getElementById("btn-back-to-360");
  const wrapper = document.getElementById("showroom-wrapper");

  // Click "View Photos" -> Switch to Photo Mode
  btnViewPhotos.addEventListener("click", () => {
    // Snap to top of the viewer
    window.scrollTo({ top: 0, behavior: "instant" });

    // Add classes to hide table, show gallery, and lock scroll
    wrapper.classList.add("mode-photos");
    document.body.classList.add("no-scroll");
  });

  // Click "Back" -> Switch to 360 Mode
  btnBackTo360.addEventListener("click", () => {
    // Remove classes to show table and enable scroll
    wrapper.classList.remove("mode-photos");
    document.body.classList.remove("no-scroll");
  });

  /* ==============================================
       3. Photo Slider Logic (For later)
       ============================================== */
  const slidePrev = document.getElementById("slide-prev");
  const slideNext = document.getElementById("slide-next");

  slidePrev.addEventListener("click", () => console.log("Left Arrow Clicked"));
  slideNext.addEventListener("click", () => console.log("Right Arrow Clicked"));
});
